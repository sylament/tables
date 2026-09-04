import { Controller } from "@hotwired/stimulus";
import { resolveLiveHost } from "../utils/live_host.js";
const DRAFT_MODEL = "tableDeferredColumns";
const APPLY_ACTION = "applyTableColumnManager";
const ITEM_KEY_SEPARATOR = "::";
const GROUP_ITEM_TYPE = "group";
class column_manager_controller_default extends Controller {
  static targets = ["checkbox"];
  static values = {
    live: { type: Boolean, default: false }
  };
  #component = null;
  #unsubscribe = null;
  #generation = 0;
  async connect() {
    const generation = ++this.#generation;
    let component;
    try {
      component = await resolveLiveHost(this.element);
    } catch {
      return;
    }
    if (generation !== this.#generation) {
      return;
    }
    this.#component = component;
    const onRender = () => this.#repaint();
    component.on("render:finished", onRender);
    this.#unsubscribe = () => component.off("render:finished", onRender);
    this.#repaint();
  }
  disconnect() {
    this.#generation++;
    this.#unsubscribe?.();
    this.#unsubscribe = null;
    this.#component = null;
  }
  toggle(event) {
    const checkbox = event.target;
    if (!(checkbox instanceof HTMLInputElement) || checkbox.disabled) {
      return;
    }
    const draft = this.#draft();
    const item = findColumn(draft, checkbox.value);
    if (item === void 0) {
      return;
    }
    item.isToggled = !item.isToggled;
    this.#write(draft);
  }
  /**
   * The drag control's new order. An entry the list never rendered keeps its place after the
   * rendered ones rather than being dropped, which is where vendor's array rebuild loses them.
   */
  reorder(event) {
    const ids = event.detail?.items;
    if (!Array.isArray(ids)) {
      return;
    }
    const draft = this.#draft();
    const ordered = [];
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
      const item = draft.find(
        (candidate) => type === GROUP_ITEM_TYPE ? candidate.type === GROUP_ITEM_TYPE && candidate.name === name : candidate.type !== GROUP_ITEM_TYPE && candidate.name === name
      );
      if (item !== void 0 && !ordered.includes(item)) {
        ordered.push(item);
      }
    }
    this.#write([...ordered, ...draft.filter((item) => !ordered.includes(item))]);
  }
  #draft() {
    if (this.#component === null) {
      return [];
    }
    try {
      return toItems(this.#component.getData(DRAFT_MODEL));
    } catch {
      return [];
    }
  }
  #write(draft) {
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
  #repaint() {
    const draft = this.#draft();
    for (const checkbox of this.checkboxTargets) {
      const item = findColumn(draft, checkbox.value);
      if (item !== void 0) {
        checkbox.checked = item.isToggled;
      }
    }
  }
}
function findColumn(draft, name) {
  return draft.find((item) => item.type !== GROUP_ITEM_TYPE && item.name === name);
}
function toItems(value) {
  return Array.isArray(value) ? value.filter(isItem).map((item) => ({ ...item })) : [];
}
function isItem(value) {
  if (typeof value !== "object" || value === null) {
    return false;
  }
  const candidate = value;
  return typeof candidate.type === "string" && typeof candidate.name === "string" && typeof candidate.isToggled === "boolean";
}
export {
  column_manager_controller_default as default
};
//# sourceMappingURL=column_manager_controller.js.map
