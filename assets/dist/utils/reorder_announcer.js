const DESCRIPTION_ATTRIBUTE = "data-reorder-description";
const LABEL_ATTRIBUTE = "data-reorder-label";
const DIRECTION_ATTRIBUTE = "data-reorder-direction";
const TOKENS = /__LABEL__|__POSITION__|__COUNT__/g;
class ReorderAnnouncer {
  #component;
  #options;
  #failed = /* @__PURE__ */ new WeakSet();
  #pending = null;
  #onError = (response) => {
    this.#failed.add(response);
  };
  constructor(component, options) {
    this.#component = component;
    this.#options = options;
    component.on("response:error", this.#onError);
  }
  disconnect() {
    this.#component.off("response:error", this.#onError);
    this.#pending = null;
  }
  /**
   * Sends the move's request and announces it once it lands. While another move is waiting, a
   * control's move is dropped, and a drag, which the list already shows, is sent untracked.
   */
  async track(move, request) {
    if (this.#pending !== null) {
      if (move.direction === "drag") {
        await request();
      }
      return;
    }
    const operation = {
      item: move.item,
      direction: move.direction,
      before: move.before ?? keysOf(this.#options.descriptions()),
      control: move.control,
      focus: this.#options.restoreFocus === true && move.control !== void 0 && document.activeElement === move.control
    };
    this.#pending = operation;
    let response;
    try {
      response = await request();
    } catch {
      this.#finish(operation);
      return;
    }
    if (this.#pending !== operation) {
      return;
    }
    if (!this.#failed.has(response)) {
      this.#announce(operation);
    }
    this.#finish(operation);
  }
  #finish(operation) {
    if (this.#pending === operation) {
      this.#pending = null;
    }
  }
  #announce(operation) {
    const descriptions = this.#options.descriptions();
    const after = keysOf(descriptions);
    const beforePeers = operation.before.filter((key) => after.includes(key));
    const afterPeers = after.filter((key) => operation.before.includes(key));
    const description = descriptions.find(
      (element) => element.getAttribute(DESCRIPTION_ATTRIBUTE) === operation.item
    );
    const status = this.#options.status();
    const message = this.#options.movedMessage();
    if (description === void 0 || status === null || message === "" || beforePeers.indexOf(operation.item) === afterPeers.indexOf(operation.item)) {
      return;
    }
    const format = new Intl.NumberFormat();
    const values = {
      __LABEL__: description.getAttribute(LABEL_ATTRIBUTE) ?? "",
      __POSITION__: format.format(after.indexOf(operation.item) + 1),
      __COUNT__: format.format(after.length)
    };
    status.textContent = message.replace(TOKENS, (token) => values[token] ?? token);
    if (operation.focus) {
      this.#restoreFocus(operation, description);
    }
  }
  /** `reordering.js`'s hand-on, taken only while focus is where the move left it. */
  #restoreFocus(operation, description) {
    const active = document.activeElement;
    if (active !== document.body && active !== operation.control) {
      return;
    }
    const controls = Array.from(
      document.querySelectorAll(`[${DIRECTION_ATTRIBUTE}]`)
    ).filter(
      (control2) => describes(control2, description) && !control2.matches(":disabled") && control2.getAttribute("aria-disabled") !== "true" && control2.getClientRects().length > 0
    );
    const control = controls.find(
      (element) => element.getAttribute(DIRECTION_ATTRIBUTE) === operation.direction
    ) ?? controls[0];
    control?.focus({ preventScroll: true });
  }
}
function readMoveControl(control) {
  const direction = control.getAttribute(DIRECTION_ATTRIBUTE);
  if (direction !== "up" && direction !== "down") {
    return null;
  }
  for (const id of (control.getAttribute("aria-describedby") ?? "").split(/\s+/)) {
    const item = id === "" ? null : control.ownerDocument.getElementById(id)?.getAttribute(DESCRIPTION_ATTRIBUTE);
    if (item !== null && item !== void 0) {
      return { item, direction };
    }
  }
  return null;
}
function orderBeforeDrag(after, item, oldIndex, newIndex) {
  const before = [...after];
  if (before[newIndex] !== item) {
    return before;
  }
  before.splice(newIndex, 1);
  before.splice(oldIndex, 0, item);
  return before;
}
function keysOf(descriptions) {
  return descriptions.map((element) => element.getAttribute(DESCRIPTION_ATTRIBUTE) ?? "");
}
function describes(control, description) {
  return description.id !== "" && (control.getAttribute("aria-describedby") ?? "").split(/\s+/).includes(description.id);
}
export {
  ReorderAnnouncer,
  orderBeforeDrag,
  readMoveControl
};
//# sourceMappingURL=reorder_announcer.js.map
