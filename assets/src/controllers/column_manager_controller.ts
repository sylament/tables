import { Controller } from "@hotwired/stimulus";
import type { Component } from "@symfony/ux-live-component";

import { resolveLiveHost } from "../utils/live_host.js";

const DRAFT_MODEL = "tableDeferredColumns";
const APPLY_ACTION = "applyTableColumnManager";
const ITEM_KEY_SEPARATOR = "::";
const GROUP_ITEM_TYPE = "group";

/** One entry of the host's draft prop — the stored shape, `group` never produced today. */
type ColumnStateItem = {
  type: string;
  name: string;
  isToggled: boolean;
  isToggledHiddenByDefault: boolean | null;
};

/**
 * The column manager's draft, written whole into the host's ValueStore so N ticks and drags cost
 * zero requests — the port of Filament's `filamentTableColumnManager` data over the mechanism the
 * record-selection controller already uses. Apply and reset are plain live actions and need no
 * hand from this controller: the dirty draft rides every request.
 */
export default class extends Controller<HTMLElement> {
  static targets = ["checkbox"];

  static values = {
    live: { type: Boolean, default: false },
  };

  declare readonly checkboxTargets: HTMLInputElement[];
  declare readonly liveValue: boolean;

  #component: Component | null = null;
  #unsubscribe: (() => void) | null = null;
  #generation = 0;

  async connect(): Promise<void> {
    const generation = ++this.#generation;
    let component: Component;

    try {
      component = await resolveLiveHost(this.element);
    } catch {
      // No live host answered, so there is no draft to write: the checkboxes stay inert rather
      // than throwing on every tick.
      return;
    }

    if (generation !== this.#generation) {
      return;
    }

    this.#component = component;

    const onRender = (): void => this.#repaint();
    component.on("render:finished", onRender);
    this.#unsubscribe = (): void => component.off("render:finished", onRender);

    this.#repaint();
  }

  disconnect(): void {
    this.#generation++;

    this.#unsubscribe?.();
    this.#unsubscribe = null;

    this.#component = null;
  }

  toggle(event: Event): void {
    const checkbox = event.target;

    if (!(checkbox instanceof HTMLInputElement) || checkbox.disabled) {
      return;
    }

    const draft = this.#draft();
    const item = findColumn(draft, checkbox.value);

    if (item === undefined) {
      return;
    }

    item.isToggled = !item.isToggled;
    this.#write(draft);
  }

  /**
   * The drag control's new order. An entry the list never rendered keeps its place after the
   * rendered ones rather than being dropped, which is where vendor's array rebuild loses them.
   */
  reorder(event: Event): void {
    const ids = (event as CustomEvent<{ items?: unknown }>).detail?.items;

    if (!Array.isArray(ids)) {
      return;
    }

    const draft = this.#draft();
    const ordered: ColumnStateItem[] = [];

    for (const id of ids) {
      if (typeof id !== "string") {
        continue;
      }

      const separatorAt = id.indexOf(ITEM_KEY_SEPARATOR);

      if (separatorAt === -1) {
        continue;
      }

      const type = id.slice(0, separatorAt);
      const name = id.slice(separatorAt + ITEM_KEY_SEPARATOR.length);
      const item = draft.find((candidate) =>
        type === GROUP_ITEM_TYPE
          ? candidate.type === GROUP_ITEM_TYPE && candidate.name === name
          : candidate.type !== GROUP_ITEM_TYPE && candidate.name === name,
      );

      if (item !== undefined && !ordered.includes(item)) {
        ordered.push(item);
      }
    }

    this.#write([...ordered, ...draft.filter((item) => !ordered.includes(item))]);
  }

  #draft(): ColumnStateItem[] {
    if (this.#component === null) {
      return [];
    }

    try {
      return toItems(this.#component.getData(DRAFT_MODEL));
    } catch {
      return [];
    }
  }

  #write(draft: ColumnStateItem[]): void {
    const component = this.#component;

    if (component === null) {
      return;
    }

    component.set(DRAFT_MODEL, draft, false);
    this.#repaint();

    if (this.liveValue) {
      void component.action(APPLY_ACTION);
    }
  }

  #repaint(): void {
    const draft = this.#draft();

    for (const checkbox of this.checkboxTargets) {
      const item = findColumn(draft, checkbox.value);

      if (item !== undefined) {
        checkbox.checked = item.isToggled;
      }
    }
  }
}

function findColumn(draft: ColumnStateItem[], name: string): ColumnStateItem | undefined {
  return draft.find((item) => item.type !== GROUP_ITEM_TYPE && item.name === name);
}

/** The draft is client-writable, so anything but a list of item-shaped objects reads as empty; items are copied because the store hands out its own references. */
function toItems(value: unknown): ColumnStateItem[] {
  return Array.isArray(value) ? value.filter(isItem).map((item) => ({ ...item })) : [];
}

function isItem(value: unknown): value is ColumnStateItem {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const candidate = value as { type?: unknown; name?: unknown; isToggled?: unknown };

  return (
    typeof candidate.type === "string" &&
    typeof candidate.name === "string" &&
    typeof candidate.isToggled === "boolean"
  );
}
