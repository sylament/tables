import type { Component } from "@symfony/ux-live-component";

const DESCRIPTION_ATTRIBUTE = "data-reorder-description";
const LABEL_ATTRIBUTE = "data-reorder-label";
const DIRECTION_ATTRIBUTE = "data-reorder-direction";
const TOKENS = /__LABEL__|__POSITION__|__COUNT__/g;

type BackendResponse = Awaited<ReturnType<Component["action"]>>;

export type ReorderDirection = "up" | "down" | "drag";

/** One move of one item: the key its description names, how it moved, and the order before it. */
export type ReorderMove = {
  item: string;
  direction: ReorderDirection;
  /** The keys in their order before the move; read from the descriptions when left out. */
  before?: string[];
  /** The move control that asked for it, whose focus a focus-restoring list hands on. */
  control?: HTMLElement;
};

export type ReorderAnnouncerOptions = {
  /** The list's own item descriptions, in document order: the peers a move is counted among. */
  descriptions: () => HTMLElement[];
  /** The list's status region; the announcer is its only writer. */
  status: () => HTMLElement | null;
  /** The catalogue's moved message, holding the `__LABEL__`, `__POSITION__` and `__COUNT__` tokens. */
  movedMessage: () => string;
  /** Focus the moved item's same-direction control once the move lands, else its other one. */
  restoreFocus?: boolean;
};

type Operation = {
  item: string;
  direction: ReorderDirection;
  before: string[];
  control: HTMLElement | undefined;
  focus: boolean;
};

/**
 * Announces a landed move in a server-ordered list, the port of `table.js`'s `finishTableReorder()`
 * and `reordering.js`'s `reconcile()`: the move's request resolves once its re-render is morphed
 * in, and only when the item's index among the keys both orders share changed is the moved
 * message written. A request UX answers with an error is never announced.
 */
export class ReorderAnnouncer {
  readonly #component: Component;
  readonly #options: ReorderAnnouncerOptions;
  readonly #failed = new WeakSet<BackendResponse>();
  #pending: Operation | null = null;

  readonly #onError = (response: BackendResponse): void => {
    this.#failed.add(response);
  };

  constructor(component: Component, options: ReorderAnnouncerOptions) {
    this.#component = component;
    this.#options = options;

    component.on("response:error", this.#onError);
  }

  disconnect(): void {
    this.#component.off("response:error", this.#onError);
    this.#pending = null;
  }

  /**
   * Sends the move's request and announces it once it lands. While another move is waiting, a
   * control's move is dropped, and a drag, which the list already shows, is sent untracked.
   */
  async track(move: ReorderMove, request: () => Promise<BackendResponse>): Promise<void> {
    if (this.#pending !== null) {
      if (move.direction === "drag") {
        await request();
      }

      return;
    }

    const operation: Operation = {
      item: move.item,
      direction: move.direction,
      before: move.before ?? keysOf(this.#options.descriptions()),
      control: move.control,
      focus:
        this.#options.restoreFocus === true &&
        move.control !== undefined &&
        document.activeElement === move.control,
    };

    this.#pending = operation;

    let response: BackendResponse;

    try {
      response = await request();
    } catch {
      // A rejected request leaves no verified order to announce.
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

  #finish(operation: Operation): void {
    if (this.#pending === operation) {
      this.#pending = null;
    }
  }

  #announce(operation: Operation): void {
    const descriptions = this.#options.descriptions();
    const after = keysOf(descriptions);
    const beforePeers = operation.before.filter((key) => after.includes(key));
    const afterPeers = after.filter((key) => operation.before.includes(key));
    const description = descriptions.find(
      (element) => element.getAttribute(DESCRIPTION_ATTRIBUTE) === operation.item,
    );
    const status = this.#options.status();
    const message = this.#options.movedMessage();

    if (
      description === undefined ||
      status === null ||
      message === "" ||
      beforePeers.indexOf(operation.item) === afterPeers.indexOf(operation.item)
    ) {
      return;
    }

    const format = new Intl.NumberFormat();
    const values: Record<string, string> = {
      __LABEL__: description.getAttribute(LABEL_ATTRIBUTE) ?? "",
      __POSITION__: format.format(after.indexOf(operation.item) + 1),
      __COUNT__: format.format(after.length),
    };

    status.textContent = message.replace(TOKENS, (token) => values[token] ?? token);

    if (operation.focus) {
      this.#restoreFocus(operation, description);
    }
  }

  /** `reordering.js`'s hand-on, taken only while focus is where the move left it. */
  #restoreFocus(operation: Operation, description: HTMLElement): void {
    const active = document.activeElement;

    if (active !== document.body && active !== operation.control) {
      return;
    }

    const controls = Array.from(
      document.querySelectorAll<HTMLElement>(`[${DIRECTION_ATTRIBUTE}]`),
    ).filter(
      (control) =>
        describes(control, description) &&
        !control.matches(":disabled") &&
        control.getAttribute("aria-disabled") !== "true" &&
        control.getClientRects().length > 0,
    );
    const control =
      controls.find(
        (element) => element.getAttribute(DIRECTION_ATTRIBUTE) === operation.direction,
      ) ?? controls[0];

    control?.focus({ preventScroll: true });
  }
}

/**
 * The move a control asks for: the item its `aria-describedby` description names, and the
 * direction it carries; `null` for anything else.
 */
export function readMoveControl(
  control: Element,
): { item: string; direction: "up" | "down" } | null {
  const direction = control.getAttribute(DIRECTION_ATTRIBUTE);

  if (direction !== "up" && direction !== "down") {
    return null;
  }

  for (const id of (control.getAttribute("aria-describedby") ?? "").split(/\s+/)) {
    const item =
      id === ""
        ? null
        : control.ownerDocument.getElementById(id)?.getAttribute(DESCRIPTION_ATTRIBUTE);

    if (item !== null && item !== undefined) {
      return { item, direction };
    }
  }

  return null;
}

/**
 * The order a drag started from, rebuilt from the order it ended in: support's sortable control
 * announces only the end of a drag, once the list already shows the new order.
 */
export function orderBeforeDrag(
  after: string[],
  item: string,
  oldIndex: number,
  newIndex: number,
): string[] {
  const before = [...after];

  if (before[newIndex] !== item) {
    return before;
  }

  before.splice(newIndex, 1);
  before.splice(oldIndex, 0, item);

  return before;
}

function keysOf(descriptions: HTMLElement[]): string[] {
  return descriptions.map((element) => element.getAttribute(DESCRIPTION_ATTRIBUTE) ?? "");
}

function describes(control: Element, description: HTMLElement): boolean {
  return (
    description.id !== "" &&
    (control.getAttribute("aria-describedby") ?? "").split(/\s+/).includes(description.id)
  );
}
