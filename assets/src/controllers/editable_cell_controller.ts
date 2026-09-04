import { Controller } from "@hotwired/stimulus";
import type { Component } from "@symfony/ux-live-component";

import { resolveLiveHost } from "../utils/live_host.js";

const UPDATE_ACTION = "updateTableColumnState";
const LOADING_CLASS = "sy-loading";
const CONTROL_SELECTOR = "input, select";

type EditableControl = HTMLInputElement | HTMLSelectElement;

/**
 * One editable cell: it commits its control's new value through the host's write action and
 * repaints the control from the cell root's authoritative `state` value.
 *
 * The repaint is what makes the seam server-canonical. UX's `UnsyncedInputsTracker` pins every
 * control the user has touched through every morph, so a refused write would otherwise leave the
 * refused value on screen forever and a polled foreign change would never show.
 */
export default class extends Controller<HTMLElement> {
  static values = {
    column: String,
    record: String,
    state: String,
  };

  declare readonly columnValue: string;
  declare readonly recordValue: string;
  declare readonly stateValue: string;

  #component: Component | null = null;
  #unsubscribe: (() => void) | null = null;
  #generation = 0;
  #writes = 0;

  async connect(): Promise<void> {
    this.#repaint();

    await this.#host();
  }

  disconnect(): void {
    this.#generation++;

    this.#unsubscribe?.();
    this.#unsubscribe = null;

    this.#component = null;
  }

  stateValueChanged(): void {
    this.#repaint();
  }

  /**
   * The control's new value, handed to the host. The action's answer is never read: the host
   * writes state and re-renders, and the re-render plus the repaint are the whole reply.
   */
  async commit(event: Event): Promise<void> {
    const control = event.target;

    if (!isEditableControl(control)) {
      return;
    }

    const input = readInput(control);
    const component = await this.#host();

    if (component === null) {
      return;
    }

    const wasDisabled = control.disabled;

    this.#writes++;
    this.element.classList.add(LOADING_CLASS);
    control.disabled = true;

    try {
      await component.action(UPDATE_ACTION, {
        column: this.columnValue,
        record: this.recordValue,
        input,
      });
    } finally {
      this.#writes--;

      if (this.#writes === 0) {
        this.element.classList.remove(LOADING_CLASS);
      }

      control.disabled = wasDisabled;

      this.#repaint();
    }
  }

  #repaint(): void {
    const control = this.#control();

    if (control === null || this.#writes > 0) {
      return;
    }

    if (
      control.ownerDocument.activeElement === control &&
      clientState(control) !== this.stateValue
    ) {
      return;
    }

    if (isCheckbox(control)) {
      control.checked = this.stateValue === "1";

      return;
    }

    control.value = this.stateValue;
  }

  /**
   * The host, resolved on connect and retried on first use so a control changed before that race
   * settles still writes. Subscribing to the re-render is part of resolving it, because the
   * repaint is what a resolution too late for `connect()` would otherwise have lost.
   */
  async #host(): Promise<Component | null> {
    if (this.#component !== null) {
      return this.#component;
    }

    const generation = this.#generation;
    let component: Component;

    try {
      component = await resolveLiveHost(this.element);
    } catch {
      // No live host answered, so there is nowhere to write: the control stays inert rather than
      // throwing on every change. The cell needs both halves, and the missing half is the one
      // this controller cannot supply.
      return null;
    }

    if (generation !== this.#generation) {
      return null;
    }

    this.#component = component;

    const onRender = (): void => this.#repaint();

    component.on("render:finished", onRender);
    this.#unsubscribe = (): void => component.off("render:finished", onRender);

    this.#repaint();

    return component;
  }

  #control(): EditableControl | null {
    const control = this.element.querySelector(CONTROL_SELECTOR);

    return isEditableControl(control) ? control : null;
  }
}

/** A checkbox posts a JSON boolean, everything else its string value. */
function readInput(control: EditableControl): string | boolean {
  return isCheckbox(control) ? control.checked : control.value;
}

/** The control's value in the vocabulary the root's `state` publishes: `'1'`/`'0'`, else the string. */
function clientState(control: EditableControl): string {
  if (isCheckbox(control)) {
    return control.checked ? "1" : "0";
  }

  return control.value;
}

function isCheckbox(control: EditableControl): control is HTMLInputElement {
  return control instanceof HTMLInputElement && control.type === "checkbox";
}

function isEditableControl(value: unknown): value is EditableControl {
  return value instanceof HTMLInputElement || value instanceof HTMLSelectElement;
}
