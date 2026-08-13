(function () {
  'use strict';
  var root = document.getElementById('cgb-admin');
  if (!root) return;
  var adminSegment = window.location.pathname.split('/').filter(Boolean)[0] || 'admin';
  var apiBase = '/' + adminSegment + '/v1/community_group_buy';
  var state = { products: [], settings: null, original: null, normal: [], subsidy: [] };
  var messageTimer = 0;

  function el(id) { return document.getElementById(id); }
  function node(tag, className, text) { var n = document.createElement(tag); if (className) n.className = className; if (text !== undefined) n.textContent = text; return n; }
  function headers(body) { var h = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }; var jwt = localStorage.getItem('backJwt'); if (jwt) h.Authorization = 'Bearer ' + jwt; if (body) h['Content-Type'] = 'application/json'; return h; }
  function request(path, options) {
    options = options || {}; options.credentials = 'same-origin'; options.headers = headers(!!options.body);
    return fetch(apiBase + path, options).catch(function () { throw new Error('未能确认结果，请刷新核对'); }).then(function (r) {
      return r.text().then(function (text) {
        var payload; try { payload = JSON.parse(text); } catch (e) { throw new Error('服务暂时异常，请稍后重试'); }
        if (!payload || Number(payload.status) !== 200) throw new Error(payload && payload.msg ? payload.msg : '请求失败');
        return payload.data || {};
      });
    });
  }
  function message(text, ok) {
    var box = el('cgb-message'); box.textContent = text; box.className = 'cgb-message ' + (ok ? 'is-success' : 'is-error'); box.hidden = false;
    clearTimeout(messageTimer); messageTimer = setTimeout(function () { box.hidden = true; }, ok ? 6500 : 8000);
  }
  function money(value) { var n = Number(value || 0); return Number.isFinite(n) ? n.toFixed(2) : '0.00'; }
  function date(value) { return Number(value) > 0 ? new Date(Number(value) * 1000).toLocaleString() : '-'; }
  function contains(list, id) { return list.indexOf(Number(id)) !== -1; }
  function toggle(list, id, checked) { id = Number(id); var at = list.indexOf(id); if (checked && at === -1) list.push(id); if (!checked && at !== -1) list.splice(at, 1); list.sort(function (a, b) { return a - b; }); }
  function sameIds(left, right) { return (left || []).slice().sort().join(',') === (right || []).slice().sort().join(','); }
  function selectable(product) { return !Number(product.hidden) && String(product.pay_type) !== 'free'; }

  function renderDashboard(data) {
    var box = el('cgb-stats'); box.textContent = '';
    [['基础拼团商品', data.normal_products], ['0.1元基础款', data.subsidy_products], ['拼团中', data.gathering_groups], ['已成团', data.success_groups], ['已付款成员', data.paid_members], ['预留/总预算', '¥' + money(data.reserved_loss) + ' / ¥' + money(data.subsidy_budget)]].forEach(function (item) {
      var card = node('article'); card.appendChild(node('span', '', item[0])); card.appendChild(node('strong', '', String(item[1] === undefined ? 0 : item[1]))); box.appendChild(card);
    });
    var economics = el('cgb-economics'); economics.textContent = '';
    ((data.economics || {}).plans || []).forEach(function (plan) {
      var card = node('article'); card.appendChild(node('strong', '', plan.target_size + '人团·' + plan.display_discount));
      card.appendChild(node('span', '', '按售价计毛利 ' + (Number(plan.gross_profit_bps_of_list) / 100).toFixed(0) + '% · 按收入计毛利率 ' + (Number(plan.gross_margin_bps_of_revenue) / 100).toFixed(2) + '%'));
      economics.appendChild(card);
    });
    if (!data.dependency || !data.dependency.enabled) message('官方优惠码插件未启用，团购权益暂不可用', false);
  }

  function productPicker(containerId, mode, search) {
    var box = el(containerId); box.textContent = '';
    var selected = mode === 'normal' ? state.normal : state.subsidy;
    var keyword = String(search || '').trim().toLowerCase();
    var visible = state.products.filter(function (product) {
      return !keyword || String(product.id).indexOf(keyword) !== -1 || String(product.name || '').toLowerCase().indexOf(keyword) !== -1;
    });
    visible.forEach(function (product) {
      var label = node('label', 'cgb-product-option' + (selectable(product) ? '' : ' is-disabled'));
      var input = document.createElement('input'); input.type = 'checkbox'; input.checked = contains(selected, product.id); input.disabled = !selectable(product);
      input.onchange = function () { toggle(selected, product.id, input.checked); label.classList.toggle('is-selected', input.checked); };
      label.classList.toggle('is-selected', input.checked);
      var content = node('span'); var title = node('strong', '', product.name || '未命名商品'); title.appendChild(node('small', '', ' #' + product.id)); content.appendChild(title);
      var meta = mode === 'subsidy'
        ? '基础售价 ¥' + money(product.price) + ' · 10人团预留亏损 ¥' + money(product.subsidy_group_loss)
        : '基础售价 ¥' + money(product.price) + (product.group_name ? ' · ' + product.group_name : '');
      if (!selectable(product)) meta += Number(product.hidden) ? ' · 已隐藏，不可选' : ' · 免费商品，不可选';
      content.appendChild(node('small', '', meta)); label.appendChild(input); label.appendChild(content); box.appendChild(label);
    });
    if (!visible.length) box.appendChild(node('p', 'cgb-empty', '没有匹配的商品'));
  }
  function renderProducts() {
    productPicker('cgb-normal-products', 'normal', el('cgb-normal-search').value);
    productPicker('cgb-subsidy-products', 'subsidy', el('cgb-subsidy-search').value);
  }
  function fillSettings(settings) {
    state.settings = settings; state.original = JSON.parse(JSON.stringify(settings));
    state.normal = (settings.normal_product_ids || []).map(Number); state.subsidy = (settings.subsidy_product_ids || []).map(Number);
    el('cgb-subsidy-enabled').checked = Number(settings.subsidy_enabled) === 1;
    el('cgb-budget').value = settings.subsidy_budget || '0.00'; el('cgb-lifetime').value = settings.subsidy_lifetime_limit || 1;
    el('cgb-group-expire').value = settings.group_expire_minutes || 1440; el('cgb-claim-expire').value = settings.claim_expire_minutes || 1440;
    renderProducts();
  }
  function loadProducts() { return request('/products').then(function (data) { state.products = data.list || []; renderProducts(); }); }
  function loadSettings() { return request('/settings').then(function (data) { fillSettings(data.settings || {}); }); }
  function loadDashboard() { return request('/dashboard').then(renderDashboard); }

  function saveSettings(event) {
    event.preventDefault();
    var removed = state.original && (!sameIds(state.original.normal_product_ids, state.normal) || !sameIds(state.original.subsidy_product_ids, state.subsidy)
      || (Number(state.original.subsidy_enabled) === 1 && !el('cgb-subsidy-enabled').checked));
    if (removed && !window.confirm('这次保存会终止已移出商品的未成团，已成团权益不受影响。确定继续吗？')) return;
    var button = el('cgb-save'); var oldText = button.textContent; button.disabled = true; button.textContent = '正在保存…';
    var body = {
      normal_product_ids: state.normal, subsidy_product_ids: state.subsidy,
      subsidy_enabled: el('cgb-subsidy-enabled').checked ? 1 : 0,
      subsidy_budget: el('cgb-budget').value || '0', subsidy_lifetime_limit: Number(el('cgb-lifetime').value || 1),
      group_expire_minutes: Number(el('cgb-group-expire').value || 1440), claim_expire_minutes: Number(el('cgb-claim-expire').value || 1440)
    };
    request('/settings', { method: 'PUT', body: JSON.stringify(body) }).then(function (result) {
      var suffix = Number(result.expired_groups) > 0 ? '，已终止 ' + result.expired_groups + ' 个未成团' : '';
      return Promise.all([loadSettings(), loadDashboard()]).then(function () { message('白名单与风控设置已保存' + suffix, true); }, function () {
        message('设置已保存，但页面刷新未完成，请手动刷新核对', true);
      });
    }).catch(function (error) { message(error.message, false); }).finally(function () { button.disabled = false; button.textContent = oldText; });
  }

  function loadGroups(showResult) {
    var button = el('cgb-refresh-groups'); var oldText = button.textContent; button.disabled = true; button.textContent = '正在刷新…';
    return request('/groups').then(function (data) {
      var body = el('cgb-groups'); body.textContent = '';
      (data.list || []).forEach(function (group) {
        var tr = node('tr');
        [group.group_no, '#' + group.product_id + ' ' + (group.product_name || '-'), group.mode_label,
          group.joined_count + '/' + group.target_size, group.status, '¥' + money(group.reserved_loss),
          group.success_time ? date(group.success_time) : date(group.expire_time)].forEach(function (value) { tr.appendChild(node('td', '', String(value || '-'))); });
        body.appendChild(tr);
      });
      if (!data.list || !data.list.length) { var tr = node('tr'); var td = node('td', 'cgb-empty', '暂无拼团记录'); td.colSpan = 7; tr.appendChild(td); body.appendChild(tr); }
      if (showResult) message('拼团记录已更新', true);
    }).catch(function (error) { message(error.message, false); }).finally(function () { button.disabled = false; button.textContent = oldText; });
  }

  root.querySelectorAll('[data-tab]').forEach(function (button) {
    button.onclick = function () {
      var tab = button.getAttribute('data-tab'); root.querySelectorAll('[data-tab]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
      root.querySelectorAll('[data-panel]').forEach(function (panel) { panel.classList.toggle('is-active', panel.getAttribute('data-panel') === tab); });
      if (tab === 'groups') loadGroups(false);
    };
  });
  el('cgb-normal-search').oninput = renderProducts; el('cgb-subsidy-search').oninput = renderProducts;
  el('cgb-settings-form').addEventListener('submit', saveSettings); el('cgb-refresh-groups').onclick = function () { loadGroups(true); };
  Promise.all([loadProducts(), loadSettings(), loadDashboard()]).catch(function (error) { message(error.message, false); });
})();
