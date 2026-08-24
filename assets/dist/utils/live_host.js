import { getComponent } from "@symfony/ux-live-component";
async function resolveLiveHost(element) {
  const root = element.closest('[data-controller~="live"]');
  if (root === null) {
    throw new Error("No live component host above the element.");
  }
  try {
    return await getComponent(root);
  } catch {
    await new Promise((resolve) => {
      const onConnect = (event) => {
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
export {
  resolveLiveHost
};
//# sourceMappingURL=live_host.js.map
