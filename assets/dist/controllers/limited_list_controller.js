import { Controller } from "@hotwired/stimulus";
const OVER_LIMIT_SELECTOR = "[data-over-limit]";
const EXPANDED_ATTRIBUTE = "data-expanded";
class limited_list_controller_default extends Controller {
  static targets = ["expandToggle", "collapseToggle"];
  static values = {
    expanded: Boolean
  };
  connect() {
    for (const toggle of this.#toggles) {
      toggle.setAttribute("tabindex", "0");
    }
    this.#apply();
  }
  expand(event) {
    this.#set(event, true);
  }
  collapse(event) {
    this.#set(event, false);
  }
  expandedValueChanged() {
    this.#apply();
  }
  get #toggles() {
    return [...this.expandToggleTargets, ...this.collapseToggleTargets];
  }
  #set(event, expanded) {
    event.preventDefault();
    event.stopPropagation();
    this.expandedValue = expanded;
  }
  #apply() {
    const expanded = this.expandedValue;
    this.element.setAttribute(EXPANDED_ATTRIBUTE, String(expanded));
    for (const item of this.element.querySelectorAll(OVER_LIMIT_SELECTOR)) {
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
export {
  limited_list_controller_default as default
};
//# sourceMappingURL=limited_list_controller.js.map
