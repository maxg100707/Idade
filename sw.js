// Service worker da programação AI Brasil Experience 2026.
// Recebe as notificações push do servidor (lembretes e mudanças na programação),
// exibe as notificações da página (exigido no Android) e abre o site ao tocar nelas.
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", e => e.waitUntil(self.clients.claim()));
self.addEventListener("push", e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch { d = {body: e.data && e.data.text()}; }
  e.waitUntil(self.registration.showNotification(d.title || "AI Brasil Experience 2026", {
    body: d.body || "", tag: d.tag, renotify: !!d.tag, icon: "icon-180.png", badge: "icon-180.png",
    data: {url: d.url || "./"}, lang: "pt-BR"
  }));
});
self.addEventListener("notificationclick", e => {
  e.notification.close();
  const url = new URL(e.notification.data?.url || "./", self.registration.scope).href;
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({type: "window", includeUncontrolled: true});
    for (const w of wins) if (w.url.startsWith(self.registration.scope)) { await w.focus(); if ("navigate" in w) return w.navigate(url); return; }
    return self.clients.openWindow(url);
  })());
});
