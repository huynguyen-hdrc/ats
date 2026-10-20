/* Router theo History API. Đường dẫn khớp chính xác hoặc theo tiền tố "/settings/*". */

const routes = [];
let notFound = null;

export function route(path, handler) { routes.push({ path, handler }); }
export function fallback(handler) { notFound = handler; }

export function navigate(path, { replace = false } = {}) {
  if (path === location.pathname + location.search) return dispatch();
  history[replace ? 'replaceState' : 'pushState']({}, '', path);
  return dispatch();
}

export function dispatch() {
  const path = location.pathname.replace(/\/+$/, '') || '/';
  const hit = routes.find((r) => r.path === path)
    || routes.find((r) => r.path.endsWith('/*') && path.startsWith(r.path.slice(0, -2)));
  const params = Object.fromEntries(new URLSearchParams(location.search));
  return (hit ? hit.handler : notFound)?.({ path, params });
}

export function startRouter() {
  window.addEventListener('popstate', dispatch);
  // Link nội bộ: <a href="/..." data-link> đi qua router, không tải lại trang.
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-link]');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey || a.target === '_blank') return;
    e.preventDefault();
    navigate(a.getAttribute('href'));
  });
  return dispatch();
}
