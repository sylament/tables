import { Controller } from "@hotwired/stimulus";
import { resolveLiveHost } from "../utils/live_host.js";
const UPDATE_ACTION = "updateTableColumnState";
const LOADING_CLASS = "sy-loading";
const CONTROL_SELECTOR = "input, select";
class editable_cell_controller_default extends Controller {
  static values = {
    column: String,
    record: String,
    state: String
  };
  #component = null;
  #unsubscribe = null;
  #generation = 0;
  #writes = 0;
  async connect() {
    this.#repaint();
    await this.#host();
  }
  disconnect() {
    this.#generation++;
    this.#unsubscribe?.();
    this.#unsubscribe = null;
    this.#component = null;
  }
  stateValueChanged() {
    this.#repaint();
  }
  /**
   * The control's new value, handed to the host. The action's answer is never read: the host
   * writes state and re-renders, and the re-render plus the repaint are the whole reply.
   */
  async commit(event) {
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
        input
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
  #repaint() {
    const control = this.#control();
    if (control === null || this.#writes > 0) {
      return;
    }
    if (control.ownerDocument.activeElement === control && clientState(control) !== this.stateValue) {
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
  async #host() {
    if (this.#component !== null) {
      return this.#component;
    }
    const generation = this.#generation;
    let component;
    try {
      component = await resolveLiveHost(this.element);
    } catch {
      return null;
    }
    if (generation !== this.#generation) {
      return null;
    }
    this.#component = component;
    const onRender = () => this.#repaint();
    component.on("render:finished", onRender);
    this.#unsubscribe = () => component.off("render:finished", onRender);
    this.#repaint();
    return component;
  }
  #control() {
    const control = this.element.querySelector(CONTROL_SELECTOR);
    return isEditableControl(control) ? control : null;
  }
}
function readInput(control) {
  return isCheckbox(control) ? control.checked : control.value;
}
function clientState(control) {
  if (isCheckbox(control)) {
    return control.checked ? "1" : "0";
  }
  return control.value;
}
function isCheckbox(control) {
  return control instanceof HTMLInputElement && control.type === "checkbox";
}
function isEditableControl(value) {
  return value instanceof HTMLInputElement || value instanceof HTMLSelectElement;
}
export {
  editable_cell_controller_default as default
};
//# sourceMappingURL=editable_cell_controller.js.map
