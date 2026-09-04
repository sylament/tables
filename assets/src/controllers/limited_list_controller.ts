import { Controller } from "@hotwired/stimulus";

const OVER_LIMIT_SELECTOR = "[data-over-limit]";
const EXPANDED_ATTRIBUTE = "data-expanded";

/**
 * The expandable half of `TextColumn::limitList()`: the server renders every value and hides the
 * ones past the limit, and this reveals them in place. The state lives in the `expanded` value
 * rather than in the DOM it paints, because a live morph replays the value attribute and Stimulus
 * re-reads it — so an expanded cell stays expanded across a re-render, the way the repeater's
 * collapse state does.
 */
export default class extends Controller<HTMLElement> {
  static targets = ["expandToggle", "collapseToggle"];

  static values = {
    expanded: Boolean,
  };

  declare readonly expandToggleTargets: HTMLElement[];
  declare readonly collapseToggleTargets: HTMLElement[];
  declare expandedValue: boolean;

  connect(): void {
    for (const toggle of this.#toggles) {
      // Stamped here rather than served: the cell may render inside a record `<a>`, where a
      // `tabindex` descendant is invalid markup.
      toggle.setAttribute("tabindex", "0");
    }

    this.#apply();
  }

  expand(event: Event): void {
    this.#set(event, true);
  }

  collapse(event: Event): void {
    this.#set(event, false);
  }

  expandedValueChanged(): void {
    this.#apply();
  }

  get #toggles(): HTMLElement[] {
    return [...this.expandToggleTargets, ...this.collapseToggleTargets];
  }

  #set(event: Event, expanded: boolean): void {
    // The cell may sit inside a record link or button, whose navigation this must not trigger.
    // Stimulus' `:prevent` / `:stop` action options need 3.2+; the peer range allows 3.0.
    event.preventDefault();
    event.stopPropagation();

    this.expandedValue = expanded;
  }

  #apply(): void {
    const expanded = this.expandedValue;

    this.element.setAttribute(EXPANDED_ATTRIBUTE, String(expanded));

    for (const item of this.element.querySelectorAll<HTMLElement>(OVER_LIMIT_SELECTOR)) {
      item.hidden = !expanded;
    }

    for (const toggle of this.expandToggleTargets) {
      toggle.hidden = expanded;
    }

    for (const toggle of this.collapseToggleTargets) {
      toggle.hidden = !expanded;
    }

    for (const toggle of this.#toggles) {
      toggle.setAttribute("aria-expanded", String(expanded));
    }
  }
}
