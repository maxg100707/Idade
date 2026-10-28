// Service worker da programação AI Brasil Experience 2026.
// Exibe as notificações da página (exigido no Android) e abre o site ao tocar nelas.
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", e => e.waitUntil(self.clients.claim()));
self.addEventListener("notificationclick", e => {
  e.notification.close();
  const url = new URL(e.notification.data?.url || "./", self.registration.scope).href;
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({type: "window", includeUncontrolled: true});
    for (const w of wins) if (w.url.startsWith(self.registration.scope)) { await w.focus(); if ("navigate" in w) return w.navigate(url); return; }
    return self.clients.openWindow(url);
  })());
});
