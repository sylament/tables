import { Controller } from "@hotwired/stimulus";
import { resolveLiveHost } from "../utils/live_host.js";
const SELECTED_MODEL = "selectedTableRecords";
const DESELECTED_MODEL = "deselectedTableRecords";
const TRACKING_MODEL = "isTrackingDeselectedTableRecords";
const LOADED_MODEL = "isTableLoaded";
const SELECT_ALL_ACTION = "selectAllTableRecords";
const REORDER_ACTION = "reorderTable";
const LOAD_ACTION = "loadTable";
const GROUP_ACTION = "groupTable";
const RECORD_CHECKBOX_SELECTOR = ".sy-ta-record-checkbox";
const ROW_SELECTOR = ".sy-ta-row";
const SELECTED_ROW_CLASS = "sy-selected";
const BULK_ONLY_SELECTOR = "[data-sy-table-bulk-only]";
const COUNT_PLACEHOLDER = ":count";
const ROW_GROUP_ATTRIBUTE = "data-sy-group";
const GROUP_KEYS_ATTRIBUTE = "data-sy-group-keys";
const COLLAPSED_CLASS = "sy-collapsed";
const GROUP_HEADER_SELECTOR = ".sy-ta-group-header";
const COLLAPSIBLE_GROUP_HEADER_SELECTOR = ".sy-ta-group-header.sy-collapsible";
const GROUPING_FIELDS_SELECTOR = ".sy-ta-grouping-settings-fields";
class table_controller_default extends Controller {
  static targets = [
    "recordCheckbox",
    "pageCheckbox",
    "banner",
    "count",
    "selectAll",
    "deselectAll",
    "groupColumn",
    "groupDirection",
    "groupDirectionField",
    "groupCheckbox"
  ];
  static values = {
    canTrack: { type: Boolean, default: false },
    pageOnly: { type: Boolean, default: false },
    allSelectableCount: Number,
    deferred: { type: Boolean, default: false },
    pollInterval: Number,
    groupsCollapsedByDefault: { type: Boolean, default: false },
    countOne: String,
    countMany: String
  };
  #component = null;
  #unsubscribe = null;
  #clickController = null;
  #lastChecked = null;
  #pollTimer = null;
  #generation = 0;
  /** The group titles whose collapse is *flipped* from the default — Filament's `groupVisibility`. */
  #toggledGroups = /* @__PURE__ */ new Set();
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
    this.#watchShiftClicks();
    this.#repaint();
    this.#startPolling();
    this.#closeDeferredWindow();
  }
  disconnect() {
    this.#generation++;
    this.#unsubscribe?.();
    this.#unsubscribe = null;
    this.#clickController?.abort();
    this.#clickController = null;
    this.#stopPolling();
    this.#component = null;
    this.#lastChecked = null;
  }
  /** The ceiling is re-stamped by every morph, and the select-all link's visibility rides on it. */
  allSelectableCountValueChanged() {
    this.#repaint();
  }
  /**
   * A morph rewrites the interval in place without reconnecting the controller, so the loop is
   * re-read here rather than only at connect: entering reorder mode drops the attribute (D19) and
   * must stop the timer, leaving it stops nothing.
   */
  pollIntervalValueChanged() {
    this.#startPolling();
  }
  toggleRecord(event) {
    const checkbox = event.target;
    if (!(checkbox instanceof HTMLInputElement)) {
      return;
    }
    const state = this.#state();
    if (this.#isSelected(state, checkbox.value)) {
      this.#deselectRecords(state, [checkbox.value]);
    } else {
      this.#selectRecords(state, [checkbox.value]);
    }
    this.#push(state);
    this.#repaint();
  }
  togglePage() {
    const state = this.#state();
    const keys = this.#pageKeys();
    if (this.#areSelected(state, keys)) {
      this.#deselectRecords(state, keys);
    } else {
      this.#selectRecords(state, keys);
    }
    this.#push(state);
    this.#repaint();
  }
  selectAll() {
    if (this.canTrackValue && !this.pageOnlyValue) {
      this.#push({ selected: /* @__PURE__ */ new Set(), deselected: /* @__PURE__ */ new Set(), tracking: true });
      this.#repaint();
      return;
    }
    if (this.pageOnlyValue) {
      const state = this.#state();
      this.#selectRecords(state, this.#pageKeys());
      this.#push(state);
      this.#repaint();
      return;
    }
    this.#component?.action(SELECT_ALL_ACTION);
  }
  deselectAll() {
    this.#push({ selected: /* @__PURE__ */ new Set(), deselected: /* @__PURE__ */ new Set(), tracking: false });
    this.#repaint();
  }
  /**
   * The two selects compose the one scalar the host validates, over an action rather than a
   * writable prop: the value is untrusted and `groupTable()` is where it is checked. The fields
   * render twice — a dropdown copy and an inline one — so the changed select is the authority
   * and both copies are re-synced from it. Changing the column empties the collapse set, whose
   * titles belong to a grouping that no longer exists (Filament's `resetCollapsedGroups()`).
   */
  group(event) {
    const component = this.#component;
    const changed = event.target;
    if (component === null || !(changed instanceof HTMLSelectElement)) {
      return;
    }
    const scope = changed.closest(GROUPING_FIELDS_SELECTOR) ?? this.element;
    const column = this.groupColumnTargets.find((select) => scope.contains(select))?.value ?? "";
    const direction = column !== "" && this.groupDirectionTargets.find((select) => scope.contains(select))?.value === "desc" ? "desc" : "asc";
    if (this.groupColumnTargets.includes(changed)) {
      this.#toggledGroups.clear();
    }
    for (const select of this.groupColumnTargets) {
      select.value = column;
    }
    for (const select of this.groupDirectionTargets) {
      select.value = direction;
    }
    for (const field of this.groupDirectionFieldTargets) {
      field.hidden = column === "";
    }
    void component.action(GROUP_ACTION, { group: column, direction });
  }
  /**
   * Filament's `toggleCollapseGroup()`, keyed by the title the row carries. The collapse control
   * inside the header binds this same action, so the click is stopped before the header's own
   * handler sees it and the group toggles once — Stimulus' `:stop` option needs 3.2+ and the
   * peer range allows 3.0.
   */
  toggleGroup(event) {
    event.stopPropagation();
    const row = event.target instanceof Element ? event.target.closest(`tr[${ROW_GROUP_ATTRIBUTE}]`) : null;
    const title = row?.getAttribute(ROW_GROUP_ATTRIBUTE);
    if (title === null || title === void 0) {
      return;
    }
    if (this.#toggledGroups.has(title)) {
      this.#toggledGroups.delete(title);
    } else {
      this.#toggledGroups.add(title);
    }
    this.#repaint();
  }
  /**
   * Filament's `toggleSelectRecords()` over one group's keys: a fully selected group is cleared
   * and any other is completed, never inverted per key. The keys are server-written onto the
   * checkbox, so the tick costs no request.
   */
  toggleGroupRecords(event) {
    const checkbox = event.target;
    if (!(checkbox instanceof HTMLInputElement)) {
      return;
    }
    const keys = toKeys(safeParse(checkbox.getAttribute(GROUP_KEYS_ATTRIBUTE)));
    if (keys.length === 0) {
      return;
    }
    const state = this.#state();
    if (this.#areSelected(state, keys)) {
      this.#deselectRecords(state, keys);
    } else {
      this.#selectRecords(state, keys);
    }
    this.#push(state);
    this.#repaint();
  }
  /**
   * The drag control's new order, handed to the host's write action. The control never talks to
   * a live component, so this is the whole bridge; the host re-resolves every key it is sent.
   */
  async reorder(event) {
    const items = event.detail?.items;
    const component = this.#component;
    if (!Array.isArray(items) || component === null) {
      return;
    }
    await component.action(REORDER_ACTION, { order: toKeys(items) });
  }
  /**
   * The re-render loop `poll()` asked for, in milliseconds. It is independent of
   * the deferred window — a table polls through one, as vendor's does. Idempotent, because both
   * `connect()` and the value callback reach it; the host must be resolved, so a callback firing
   * before `connect()` (Stimulus starts the value observer first) leaves connect to start it.
   */
  #startPolling() {
    this.#stopPolling();
    if (this.#component === null || !this.hasPollIntervalValue || this.pollIntervalValue <= 0) {
      return;
    }
    this.#pollTimer = setInterval(() => {
      void this.#component?.render();
    }, this.pollIntervalValue);
  }
  #stopPolling() {
    if (this.#pollTimer === null) {
      return;
    }
    clearInterval(this.#pollTimer);
    this.#pollTimer = null;
  }
  /**
   * The deferred table's second request, which is what puts records in the
   * shell the first render carried. The store's own flag is the once-guard, so a
   * reconnect after the load fires nothing.
   */
  #closeDeferredWindow() {
    const component = this.#component;
    if (component === null || !this.deferredValue || component.getData(LOADED_MODEL) === true) {
      return;
    }
    void component.action(LOAD_ACTION);
  }
  #state() {
    const component = this.#component;
    if (component === null) {
      return { selected: /* @__PURE__ */ new Set(), deselected: /* @__PURE__ */ new Set(), tracking: false };
    }
    return {
      selected: new Set(toKeys(component.getData(SELECTED_MODEL))),
      deselected: new Set(toKeys(component.getData(DESELECTED_MODEL))),
      tracking: component.getData(TRACKING_MODEL) === true
    };
  }
  #push(next) {
    const component = this.#component;
    if (component === null) {
      return;
    }
    component.set(TRACKING_MODEL, next.tracking, false);
    component.set(SELECTED_MODEL, [...next.selected], false);
    component.set(DESELECTED_MODEL, [...next.deselected], false);
  }
  #selectRecords(state, keys) {
    for (const key of keys) {
      if (state.tracking) {
        state.deselected.delete(key);
      } else {
        state.selected.add(key);
      }
    }
  }
  #deselectRecords(state, keys) {
    for (const key of keys) {
      if (state.tracking) {
        state.deselected.add(key);
      } else {
        state.selected.delete(key);
      }
    }
  }
  #isSelected(state, key) {
    return state.tracking ? !state.deselected.has(key) : state.selected.has(key);
  }
  /** Filament's `areRecordsSelected`: no keys reads as selected. */
  #areSelected(state, keys) {
    return keys.every((key) => this.#isSelected(state, key));
  }
  /**
   * Filament's `isGroupCollapsed()` with its default folded in: the set lists the collapsed
   * groups when the table expands by default, and the expanded ones when it collapses by default.
   */
  #isGroupCollapsed(title) {
    return this.#toggledGroups.has(title) !== this.groupsCollapsedByDefaultValue;
  }
  #pageKeys() {
    return this.recordCheckboxTargets.map((checkbox) => checkbox.value);
  }
  #selectedCount(state) {
    if (!state.tracking) {
      return state.selected.size;
    }
    if (this.hasAllSelectableCountValue) {
      return Math.max(this.allSelectableCountValue - state.deselected.size, 0);
    }
    return this.#pageKeys().filter((key) => this.#isSelected(state, key)).length;
  }
  #canSelectAll(state) {
    if (this.pageOnlyValue) {
      return !this.#areSelected(state, this.#pageKeys());
    }
    if (!this.hasAllSelectableCountValue || this.allSelectableCountValue === 0) {
      return false;
    }
    return this.allSelectableCountValue !== this.#selectedCount(state);
  }
  #countLabel(count) {
    const template = count === 1 ? this.countOneValue : this.countManyValue;
    return template.replaceAll(COUNT_PLACEHOLDER, new Intl.NumberFormat().format(count));
  }
  #repaint() {
    if (this.#component === null) {
      return;
    }
    const state = this.#state();
    for (const checkbox of this.recordCheckboxTargets) {
      const isSelected = this.#isSelected(state, checkbox.value);
      checkbox.checked = isSelected;
      checkbox.closest(ROW_SELECTOR)?.classList.toggle(SELECTED_ROW_CLASS, isSelected);
    }
    if (this.hasPageCheckboxTarget) {
      const keys = this.#pageKeys();
      const selectedOnPage = keys.filter((key) => this.#isSelected(state, key)).length;
      this.pageCheckboxTarget.checked = keys.length > 0 && selectedOnPage === keys.length;
      this.pageCheckboxTarget.indeterminate = selectedOnPage > 0 && selectedOnPage < keys.length;
    }
    const count = this.#selectedCount(state);
    if (this.hasBannerTarget) {
      this.bannerTarget.hidden = count === 0;
    }
    if (this.hasCountTarget) {
      this.countTarget.textContent = this.#countLabel(count);
    }
    if (this.hasSelectAllTarget) {
      this.selectAllTarget.hidden = !this.#canSelectAll(state);
    }
    for (const element of this.element.querySelectorAll(BULK_ONLY_SELECTOR)) {
      element.hidden = count === 0;
    }
    if (this.element.querySelector(COLLAPSIBLE_GROUP_HEADER_SELECTOR) !== null) {
      for (const row of this.element.querySelectorAll(`tr[${ROW_GROUP_ATTRIBUTE}]`)) {
        const collapsed = this.#isGroupCollapsed(row.getAttribute(ROW_GROUP_ATTRIBUTE) ?? "");
        const header = row.querySelector(GROUP_HEADER_SELECTOR);
        row.classList.toggle(COLLAPSED_CLASS, collapsed && header === null);
        header?.classList.toggle(COLLAPSED_CLASS, collapsed);
        header?.querySelector("button")?.setAttribute("aria-expanded", collapsed ? "false" : "true");
      }
    }
    for (const checkbox of this.groupCheckboxTargets) {
      const keys = toKeys(safeParse(checkbox.getAttribute(GROUP_KEYS_ATTRIBUTE)));
      const selectedInGroup = keys.filter((key) => this.#isSelected(state, key)).length;
      checkbox.checked = keys.length > 0 && selectedInGroup === keys.length;
      checkbox.indeterminate = selectedInGroup > 0 && selectedInGroup < keys.length;
    }
  }
  #watchShiftClicks() {
    this.#clickController?.abort();
    this.#clickController = new AbortController();
    this.element.addEventListener(
      "click",
      (event) => {
        const checkbox = event.target;
        if (checkbox instanceof HTMLInputElement && checkbox.matches(RECORD_CHECKBOX_SELECTOR)) {
          this.#handleCheckboxClick(event, checkbox);
        }
      },
      { signal: this.#clickController.signal }
    );
  }
  /** The shift range excludes the clicked box — `toggleRecord` owns it, on the `change` that follows. */
  #handleCheckboxClick(event, checkbox) {
    const last = this.#lastChecked;
    this.#lastChecked = checkbox;
    if (last === null || !(event instanceof MouseEvent) || !event.shiftKey) {
      return;
    }
    const checkboxes = this.recordCheckboxTargets;
    const start = checkboxes.indexOf(last);
    const end = checkboxes.indexOf(checkbox);
    if (start === -1 || end === -1) {
      return;
    }
    const [from, to] = [start, end].sort((a, b) => a - b);
    const keys = checkboxes.slice(from, to + 1).filter((element) => element !== checkbox).map((element) => element.value);
    const state = this.#state();
    if (checkbox.checked) {
      this.#selectRecords(state, keys);
    } else {
      this.#deselectRecords(state, keys);
    }
    this.#push(state);
    this.#repaint();
  }
}
function toKeys(value) {
  return Array.isArray(value) ? value.filter((key) => typeof key === "string") : [];
}
function safeParse(value) {
  if (value === null) {
    return null;
  }
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
}
export {
  table_controller_default as default
};
//# sourceMappingURL=table_controller.js.map
