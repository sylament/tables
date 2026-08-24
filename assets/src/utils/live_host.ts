import { type Component, getComponent } from "@symfony/ux-live-component";

/**
 * The live component that owns `element`: its closest live root, resolved through UX Live's
 * registry, retried once after `live:connect` when the registry is not ready yet (the live
 * controller may connect after ours; `getComponent()` polls for ~55 ms and then rejects).
 */
export async function resolveLiveHost(element: Element): Promise<Component> {
  const root = element.closest<HTMLElement>('[data-controller~="live"]');

  if (root === null) {
    throw new Error("No live component host above the element.");
  }

  try {
    return await getComponent(root);
  } catch {
    await new Promise<void>((resolve) => {
      const onConnect = (event: Event): void => {
        if (event.target !== root) {
          return;
        }

        root.removeEventListener("live:connect", onConnect);
        resolve();
      };

      root.addEventListener("live:connect", onConnect);
    });

    return getComponent(root);
  }
}
