(function () {
  'use strict';

  function boot() {
    var root = document.getElementById('cgb-client');
    var createControl = document.getElementById('cgb-create');
    if (!root || !createControl) return;
    // Vue 2 挂载会替换壳层内的子节点。必须校验当前按钮实例，不能只看旧根节点。
    if (root.getAttribute('data-booted') === '1.0.0'
      && createControl.getAttribute('data-cgb-bound') === '1') return;
    root.setAttribute('data-booted', '1.0.0');

    var apiBase = '/console/v1/community_group_buy';
    var inviteParam = new URLSearchParams(window.location.search).get('join_group');
    var inviteTarget = /^\d+$/.test(inviteParam || '') ? Number(inviteParam) : 0;
    var state = {
      data: {}, mode: 'normal', productId: 0, targetSize: 3,
      requestId: newRequestId(), inviteTarget: inviteTarget, inviteHandled: false, share: null
    };
    var messageTimer = 0;

    function el(id) { return document.getElementById(id); }
    function node(tag, className, text) {
      var n = document.createElement(tag);
      if (className) n.className = className;
      if (text !== undefined) n.textContent = text;
      return n;
    }
    function headers(body) {
      var h = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
      var jwt = localStorage.getItem('jwt');
      if (jwt) h.Authorization = 'Bearer ' + jwt;
      if (body) h['Content-Type'] = 'application/json';
      return h;
    }
    function request(path, options) {
      options = options || {};
      options.credentials = 'same-origin';
      options.headers = headers(!!options.body);
      return fetch(apiBase + path, options).catch(function () {
        throw new Error('未能确认结果，请刷新核对');
      }).then(function (response) {
        return response.text().then(function (text) {
          var payload;
          try { payload = JSON.parse(text); } catch (e) { throw new Error('服务暂时异常，请稍后重试'); }
          if (!payload || Number(payload.status) !== 200) {
            throw new Error(payload && payload.msg ? payload.msg : '请求失败');
          }
          return payload.data || {};
        });
      });
    }
    function message(text, ok) {
      var box = el('cgb-client-message');
      box.textContent = text;
      box.className = 'cgb-message ' + (ok ? 'is-success' : 'is-error');
      box.hidden = false;
      clearTimeout(messageTimer);
      messageTimer = setTimeout(function () { box.hidden = true; }, ok ? 6000 : 8000);
    }
    function date(value) { return Number(value) > 0 ? new Date(Number(value) * 1000).toLocaleString() : '-'; }
    function money(value) {
      var n = Number(value || 0);
      return Number.isFinite(n) ? n.toFixed(2) : '0.00';
    }
    function button(text, className, handler) {
      var b = node('button', 'cgb-btn ' + (className || ''), text);
      b.type = 'button';
      b.onclick = handler;
      return b;
    }
    function newRequestId() {
      var bytes = new Uint8Array(16);
      if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
      else for (var i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
      return Array.prototype.map.call(bytes, function (v) {
        return ('0' + v.toString(16)).slice(-2);
      }).join('');
    }
    function productsForMode() {
      var products = state.data.products || {};
      return products[state.mode] || [];
    }
    function selectedProduct() {
      var result = null;
      productsForMode().forEach(function (product) {
        if (Number(product.id) === Number(state.productId)) result = product;
      });
      return result;
    }
    function setTab(tab) {
      root.querySelectorAll('[data-client-tab]').forEach(function (item) {
        item.classList.toggle('is-active', item.getAttribute('data-client-tab') === tab);
      });
      root.querySelectorAll('[data-client-panel]').forEach(function (panel) {
        panel.classList.toggle('is-active', panel.getAttribute('data-client-panel') === tab);
      });
    }

    function setMode(mode) {
      if (mode === 'subsidy' && !(state.data.rules && Number(state.data.rules.subsidy_enabled))) {
        message('10人0.1元基础款当前未开放，可以选择基础拼团', false);
        return;
      }
      state.mode = mode;
      state.productId = 0;
      state.targetSize = mode === 'subsidy' ? 10 : 3;
      state.requestId = newRequestId();
      renderCreate();
    }
    function renderCreateProducts() {
      var box = el('cgb-create-products');
      box.textContent = '';
      var products = productsForMode();
      products.forEach(function (product) {
        var card = node('button', 'cgb-client-card cgb-product-card'
          + (Number(product.id) === Number(state.productId) ? ' is-selected' : ''));
        card.type = 'button';
        card.appendChild(node('span', 'cgb-kicker', '#' + product.id));
        card.appendChild(node('h2', '', product.name || '未命名商品'));
        card.appendChild(node('strong', 'cgb-price', '¥' + money(product.price) + '起'));
        card.appendChild(node('p', 'cgb-muted', state.mode === 'subsidy'
          ? '10人成团后，每人首次开通价0.1元' : '选择后再决定成团人数与折扣'));
        card.onclick = function () {
          state.productId = Number(product.id);
          state.requestId = newRequestId();
          renderCreate();
        };
        box.appendChild(card);
      });
      if (!products.length) {
        box.appendChild(node('p', 'cgb-empty', state.mode === 'subsidy'
          ? '当前没有可参加的0.1元基础款' : '当前没有支持基础拼团的商品'));
      }
    }
    function renderSizes() {
      var box = el('cgb-size-options');
      box.textContent = '';
      var rates = (state.data.rules || {}).rates || { 3: 9000, 5: 8000, 10: 7000 };
      [3, 5, 10].forEach(function (size) {
        var rate = Number(rates[size] || rates[String(size)] || 0);
        var b = node('button', 'cgb-size-choice' + (state.targetSize === size ? ' is-selected' : ''));
        b.type = 'button';
        b.appendChild(node('strong', '', size + '人团'));
        b.appendChild(node('span', '', (rate / 1000).toFixed(rate % 1000 ? 1 : 0) + '折'));
        b.onclick = function () {
          state.targetSize = size;
          state.requestId = newRequestId();
          renderCreate();
        };
        box.appendChild(b);
      });
      el('cgb-size-step').hidden = state.mode === 'subsidy';
    }
    function renderSummary() {
      var product = selectedProduct();
      var summary = el('cgb-create-summary');
      var create = el('cgb-create');
      if (!product) {
        summary.textContent = '请先选择一款商品';
        create.disabled = true;
        return;
      }
      if (state.mode === 'subsidy') {
        summary.textContent = product.name + ' · 10人团 · 成团后每人首付0.1元';
      } else {
        var rates = (state.data.rules || {}).rates || {};
        var rate = Number(rates[state.targetSize] || rates[String(state.targetSize)] || 0);
        summary.textContent = product.name + ' · ' + state.targetSize + '人团 · ' + (rate / 1000) + '折';
      }
      create.disabled = false;
    }
    function renderCreate() {
      var subsidyEnabled = state.data.rules && Number(state.data.rules.subsidy_enabled);
      el('cgb-mode-normal').classList.toggle('is-selected', state.mode === 'normal');
      el('cgb-mode-subsidy').classList.toggle('is-selected', state.mode === 'subsidy');
      el('cgb-mode-subsidy').classList.toggle('is-disabled', !subsidyEnabled);
      el('cgb-mode-subsidy').disabled = !subsidyEnabled;
      renderCreateProducts();
      renderSizes();
      renderSummary();
    }

    function createGroup() {
      var product = selectedProduct();
      if (!product) {
        message('请先选择一款商品', false);
        return;
      }
      var create = el('cgb-create');
      var oldText = create.textContent;
      create.disabled = true;
      create.textContent = '正在发起…';
      request('/group', {
        method: 'POST',
        body: JSON.stringify({
          product_id: Number(product.id),
          target_size: state.mode === 'subsidy' ? 10 : state.targetSize,
          mode: state.mode,
          request_id: state.requestId
        })
      }).then(function (result) {
        state.requestId = newRequestId();
        return load().then(function () {
          message('拼团已发起，已生成上车链接和宣传图', true);
        }, function () {
          message('拼团已发起，但页面刷新未完成，请手动刷新核对', true);
        }).then(function () {
          return openShare(Number(result.group_id)).catch(function (error) {
            message('拼团已发起，但分享卡生成失败：' + error.message, false);
          });
        });
      }).catch(function (error) {
        message(error.message, false);
      }).finally(function () {
        create.disabled = !selectedProduct();
        create.textContent = oldText;
      });
    }
    function joinGroup(id, joinButton) {
      var oldText = joinButton.textContent;
      joinButton.disabled = true;
      joinButton.textContent = '正在参团…';
      request('/group/' + id + '/join', { method: 'POST', body: '{}' }).then(function (result) {
        return load().then(function () {
          message(result.status === 'success' ? '拼团成功，首次开通权益已发放' : '参团成功', true);
        }, function () {
          message('参团已提交，但页面刷新未完成，请手动刷新核对', true);
        });
      }).catch(function (error) {
        message(error.message, false);
      }).finally(function () {
        joinButton.disabled = false;
        joinButton.textContent = oldText;
      });
    }
    function renderGroups(list) {
      var box = el('cgb-client-groups');
      box.textContent = '';
      (list || []).forEach(function (group) {
        var card = node('article', 'cgb-client-card' + (group.mode === 'subsidy' ? ' is-subsidy' : ''));
        card.setAttribute('data-group-id', group.id);
        card.appendChild(node('span', 'cgb-kicker', group.mode_label));
        card.appendChild(node('h2', '', group.product_name || '未命名商品'));
        card.appendChild(node('p', 'cgb-muted', '#' + group.product_id + ' · 团号 ' + group.group_no));
        card.appendChild(node('strong', 'cgb-price', group.display_price));
        var progress = node('div', 'cgb-progress');
        var bar = node('span');
        bar.style.width = Math.min(100, Number(group.joined_count) / Number(group.target_size) * 100) + '%';
        progress.appendChild(bar);
        card.appendChild(progress);
        card.appendChild(node('p', 'cgb-muted', '已有 ' + group.joined_count + '/' + group.target_size
          + ' 人 · 还差 ' + group.remaining + ' 人'));
        card.appendChild(node('p', 'cgb-muted', '拼团截止 ' + date(group.expire_time)));
        var actions = node('div', 'cgb-actions cgb-group-actions');
        if (group.joined_by_me) {
          actions.appendChild(node('span', 'cgb-status is-on', '我已在团内'));
          actions.appendChild(button('邀请好友', 'is-primary', function () { openShare(group.id).catch(function (error) { message(error.message, false); }); }));
        } else {
          var join = button('立即参团', 'is-primary', function () { joinGroup(group.id, join); });
          actions.appendChild(join);
          actions.appendChild(button('查看邀请图', '', function () { openShare(group.id).catch(function (error) { message(error.message, false); }); }));
        }
        card.appendChild(actions);
        box.appendChild(card);
      });
      if (!list || !list.length) {
        box.appendChild(node('p', 'cgb-empty', '暂无等待成员的拼团，你可以先开一团'));
      }
    }

    function legacyCopy(text, sourceInput) {
      var input = sourceInput;
      var temporary = false;
      if (!input) {
        input = document.createElement('textarea');
        input.value = text;
        input.style.position = 'fixed';
        input.style.left = '-9999px';
        document.body.appendChild(input);
        temporary = true;
      }
      input.focus();
      input.select();
      input.setSelectionRange(0, String(text).length);
      var copied = false;
      try { copied = !!document.execCommand('copy'); } catch (e) { copied = false; }
      if (temporary) document.body.removeChild(input);
      return copied;
    }
    function copyText(text, sourceInput) {
      if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
        return navigator.clipboard.writeText(text).catch(function () {
          if (!legacyCopy(text, sourceInput)) throw new Error('复制失败');
        });
      }
      return legacyCopy(text, sourceInput) ? Promise.resolve() : Promise.reject(new Error('复制失败'));
    }
    function copyWithFeedback(text, sourceInput, copyButton, originalText) {
      copyText(text, sourceInput).then(function () {
        copyButton.textContent = '已复制';
        setTimeout(function () { copyButton.textContent = originalText; }, 1800);
      }).catch(function () {
        if (sourceInput) {
          sourceInput.focus();
          sourceInput.select();
        }
        message('自动复制失败，链接已选中，请手动复制', false);
      });
    }
    function entitlementBlock(title, code, url, special) {
      var wrap = node('div', 'cgb-entitlement ' + (special ? 'is-special' : ''));
      wrap.appendChild(node('strong', '', title));
      wrap.appendChild(node('code', '', code));
      var actions = node('div', 'cgb-actions');
      var copyButton = button('复制权益码', '', function () {
        copyWithFeedback(code, null, copyButton, '复制权益码');
      });
      var buy = node('a', 'cgb-btn is-primary', '前往购买');
      buy.href = url;
      buy.target = '_blank';
      buy.rel = 'noopener noreferrer';
      actions.appendChild(copyButton);
      actions.appendChild(buy);
      wrap.appendChild(actions);
      return wrap;
    }
    function statusLabel(status) {
      return {
        gathering: '拼团中', success: '已成团', expired: '已过期', issued: '待使用',
        prepared: '正在下单', locked: '订单待付款', used: '已使用', refunded: '已退款', joined: '拼团中'
      }[status] || status;
    }
    function renderMine(list) {
      var box = el('cgb-client-mine');
      box.textContent = '';
      (list || []).forEach(function (membership) {
        var card = node('article', 'cgb-campaign');
        var head = node('header');
        var title = node('div');
        title.appendChild(node('h3', '', membership.product_name || '未命名商品'));
        title.appendChild(node('p', '', membership.mode_label + ' · ' + membership.group_no
          + ' · ' + membership.joined_count + '/' + membership.target_size + ' 人'));
        head.appendChild(title);
        head.appendChild(node('span', 'cgb-status ' + (membership.group_status === 'success' ? 'is-on' : ''), statusLabel(membership.status)));
        card.appendChild(head);
        if (membership.code) {
          var subsidy = membership.mode === 'subsidy' || String(membership.code).indexOf('GBS-') === 0;
          card.appendChild(entitlementBlock(subsidy ? '0.1元基础款首次开通权益'
            : membership.display_discount + '首次开通权益', membership.code, membership.buy_url, subsidy));
        } else {
          var text = membership.group_status === 'gathering' ? '待成团后发放权益码'
            : (membership.status === 'used' ? '权益已使用' : '权益已过期或不可用');
          card.appendChild(node('p', 'cgb-muted', text));
        }
        box.appendChild(card);
      });
      if (!list || !list.length) box.appendChild(node('p', 'cgb-empty', '你还没有参加过拼团'));
    }

    function roundRect(ctx, x, y, width, height, radius, fillStyle) {
      var r = Math.min(radius, width / 2, height / 2);
      ctx.beginPath();
      ctx.moveTo(x + r, y);
      ctx.arcTo(x + width, y, x + width, y + height, r);
      ctx.arcTo(x + width, y + height, x, y + height, r);
      ctx.arcTo(x, y + height, x, y, r);
      ctx.arcTo(x, y, x + width, y, r);
      ctx.closePath();
      ctx.fillStyle = fillStyle;
      ctx.fill();
    }
    function drawWrappedText(ctx, text, x, y, maxWidth, lineHeight, maxLines) {
      var chars = Array.from(String(text || ''));
      var lines = [];
      var line = '';
      chars.forEach(function (char) {
        var test = line + char;
        if (line && ctx.measureText(test).width > maxWidth) {
          lines.push(line);
          line = char;
        } else line = test;
      });
      if (line) lines.push(line);
      if (lines.length > maxLines) {
        lines = lines.slice(0, maxLines);
        while (ctx.measureText(lines[maxLines - 1] + '…').width > maxWidth) {
          lines[maxLines - 1] = lines[maxLines - 1].slice(0, -1);
        }
        lines[maxLines - 1] += '…';
      }
      lines.forEach(function (value, index) { ctx.fillText(value, x, y + index * lineHeight); });
      return lines.length;
    }
    function drawQr(ctx, matrix, x, y, boxSize) {
      var size = Array.isArray(matrix) ? matrix.length : 0;
      if (!size) return false;
      var quiet = 4;
      var unit = Math.floor(boxSize / (size + quiet * 2));
      if (unit < 2) return false;
      var total = unit * (size + quiet * 2);
      var startX = x + Math.floor((boxSize - total) / 2) + quiet * unit;
      var startY = y + Math.floor((boxSize - total) / 2) + quiet * unit;
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(x, y, boxSize, boxSize);
      ctx.fillStyle = '#111827';
      matrix.forEach(function (row, rowIndex) {
        for (var column = 0; column < size; column++) {
          if (row.charAt(column) === '1') {
            ctx.fillRect(startX + column * unit, startY + rowIndex * unit, unit, unit);
          }
        }
      });
      return true;
    }
    function drawPoster(info) {
      var canvas = el('cgb-share-canvas');
      var ctx = canvas.getContext('2d');
      var group = info.group || {};
      canvas.width = 750;
      canvas.height = 1000;
      var gradient = ctx.createLinearGradient(0, 0, 750, 1000);
      gradient.addColorStop(0, group.mode === 'subsidy' ? '#9a3412' : '#312e81');
      gradient.addColorStop(0.58, group.mode === 'subsidy' ? '#ea580c' : '#5b4fdb');
      gradient.addColorStop(1, '#111827');
      ctx.fillStyle = gradient;
      ctx.fillRect(0, 0, 750, 1000);

      roundRect(ctx, 50, 46, 228, 46, 23, 'rgba(255,255,255,.16)');
      ctx.fillStyle = '#ffffff';
      ctx.font = '700 24px "PingFang SC", "Microsoft YaHei", sans-serif';
      ctx.fillText('开源社区 · 拼团上车', 69, 78);
      ctx.font = '800 52px "PingFang SC", "Microsoft YaHei", sans-serif';
      ctx.fillText('我已开团，等你上车', 50, 158);
      ctx.font = '400 23px "PingFang SC", "Microsoft YaHei", sans-serif';
      ctx.fillStyle = 'rgba(255,255,255,.8)';
      ctx.fillText('扫码登录后进入拼团广场，名额满即成团', 52, 198);

      roundRect(ctx, 50, 235, 650, 340, 28, '#ffffff');
      ctx.fillStyle = group.mode === 'subsidy' ? '#c2410c' : '#4f46e5';
      ctx.font = '700 25px "PingFang SC", "Microsoft YaHei", sans-serif';
      ctx.fillText(group.mode_label || '拼团优惠', 82, 286);
      ctx.fillStyle = '#111827';
      ctx.font = '800 42px "PingFang SC", "Microsoft YaHei", sans-serif';
      var titleLines = drawWrappedText(ctx, group.product_name || '未命名商品', 82, 337, 586, 48, 2);
      var detailY = 337 + titleLines * 48 + 2;
      ctx.font = '800 52px "PingFang SC", "Microsoft YaHei", sans-serif';
      ctx.fillStyle = group.mode === 'subsidy' ? '#ea580c' : '#4338ca';
      ctx.fillText(group.display_price || '-', 82, detailY + 45);
      ctx.font = '600 25px "PingFang SC", "Microsoft YaHei", sans-serif';
      ctx.fillStyle = '#475467';
      ctx.fillText('首次开通价 · ' + group.joined_count + '/' + group.target_size + ' 人已上车', 238, detailY + 38);
      ctx.fillStyle = '#e5e7eb';
      roundRect(ctx, 82, detailY + 74, 586, 18, 9, '#e5e7eb');
      var ratio = Math.min(1, Number(group.joined_count) / Math.max(1, Number(group.target_size)));
      roundRect(ctx, 82, detailY + 74, Math.max(18, 586 * ratio), 18, 9, group.mode === 'subsidy' ? '#f97316' : '#6366f1');
      ctx.font = '500 22px "PingFang SC", "Microsoft YaHei", sans-serif';
      ctx.fillStyle = '#667085';
      ctx.fillText('还差 ' + group.remaining + ' 人 · 截止 ' + date(group.expire_time), 82, detailY + 132);

      roundRect(ctx, 50, 610, 650, 300, 28, '#ffffff');
      var qrOk = drawQr(ctx, info.qr_matrix, 78, 635, 250);
      ctx.fillStyle = '#111827';
      ctx.font = '800 34px "PingFang SC", "Microsoft YaHei", sans-serif';
      ctx.fillText(qrOk ? '扫码立即上车' : '复制链接立即上车', 365, 687);
      ctx.font = '500 22px "PingFang SC", "Microsoft YaHei", sans-serif';
      ctx.fillStyle = '#667085';
      ctx.fillText(group.target_size + '人团 · 当前剩余 ' + group.remaining + ' 个名额', 365, 734);
      ctx.fillText('团号 ' + (group.group_no || '-'), 365, 774);
      ctx.fillText('团购只优惠首次开通', 365, 814);
      ctx.fillText('续费按商品正常续费价', 365, 854);
      ctx.font = '400 18px sans-serif';
      ctx.fillStyle = '#98a2b3';
      var shortUrl = String(info.share_url || '');
      if (shortUrl.length > 42) shortUrl = shortUrl.slice(0, 39) + '…';
      ctx.fillText(shortUrl, 80, 892);
      ctx.fillStyle = 'rgba(255,255,255,.82)';
      ctx.font = '500 20px "PingFang SC", "Microsoft YaHei", sans-serif';
      ctx.fillText('先成团，再领取账号绑定的一次性首次开通权益', 50, 958);
      canvas.setAttribute('data-ready', '1');
    }
    function closeShare() {
      el('cgb-share-modal').hidden = true;
      state.share = null;
    }
    function showShareLoading() {
      var modal = el('cgb-share-modal');
      state.share = null;
      el('cgb-share-title').textContent = '正在生成邀请卡…';
      el('cgb-share-meta').textContent = '正在生成专属上车链接和二维码';
      el('cgb-share-url').value = '';
      el('cgb-share-copy').disabled = true;
      el('cgb-share-native').disabled = true;
      el('cgb-share-download').disabled = true;
      var canvas = el('cgb-share-canvas');
      canvas.removeAttribute('data-ready');
      var ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      modal.hidden = false;
      el('cgb-share-close').focus();
    }
    function openShare(groupId) {
      showShareLoading();
      return request('/group/' + Number(groupId) + '/share').then(function (info) {
        state.share = info;
        var group = info.group || {};
        el('cgb-share-title').textContent = '拼团已开，邀请好友上车';
        el('cgb-share-meta').textContent = (group.product_name || '未命名商品') + ' · '
          + (group.mode_label || '') + ' · 还差 ' + group.remaining + ' 人';
        el('cgb-share-url').value = info.share_url || '';
        el('cgb-share-copy').disabled = false;
        el('cgb-share-native').disabled = false;
        el('cgb-share-native').hidden = !navigator.share;
        drawPoster(info);
        el('cgb-share-download').disabled = false;
        return info;
      }).catch(function (error) {
        closeShare();
        throw error;
      });
    }
    function downloadPoster() {
      if (!state.share) return;
      var canvas = el('cgb-share-canvas');
      var groupId = state.share.group ? state.share.group.id : 'group';
      var filename = '开源社区拼团-' + groupId + '.png';
      function download(url) {
        var link = document.createElement('a');
        link.download = filename;
        link.href = url;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        if (url.indexOf('blob:') === 0) setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      }
      if (canvas.toBlob) {
        canvas.toBlob(function (blob) {
          if (!blob) {
            message('宣传图生成失败，请稍后重试', false);
            return;
          }
          download(URL.createObjectURL(blob));
          message('宣传图已保存', true);
        }, 'image/png');
      } else {
        download(canvas.toDataURL('image/png'));
        message('宣传图已保存', true);
      }
    }
    function nativeShare() {
      if (!state.share || !navigator.share) return;
      var group = state.share.group || {};
      navigator.share({
        title: '邀请你参加' + (group.mode_label || '拼团'),
        text: (group.product_name || '拼团优惠') + '，还差' + group.remaining + '人，来一起上车',
        url: state.share.share_url
      }).catch(function (error) {
        if (error && error.name !== 'AbortError') message('系统分享未完成，可以复制链接或保存宣传图', false);
      });
    }

    function handleInviteTarget(data) {
      if (!state.inviteTarget || state.inviteHandled) return;
      state.inviteHandled = true;
      var target = null;
      (data.open_groups || []).forEach(function (group) {
        if (Number(group.id) === state.inviteTarget) target = group;
      });
      setTab('groups');
      if (!target) {
        message('邀请的拼团已成团、已过期或不再开放，可以看看其他拼团', false);
        return;
      }
      var card = root.querySelector('[data-group-id="' + state.inviteTarget + '"]');
      if (card) {
        card.classList.add('is-invite-target');
        setTimeout(function () { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 60);
      }
      message(target.joined_by_me ? '你已在这个团内，可以继续邀请好友上车'
        : '好友邀请你参加“' + target.product_name + '”，点击立即参团', true);
    }
    function load() {
      return request('').then(function (data) {
        state.data = data;
        var valid = false;
        productsForMode().forEach(function (product) {
          if (Number(product.id) === Number(state.productId)) valid = true;
        });
        if (!valid) state.productId = 0;
        renderCreate();
        renderGroups(data.open_groups || []);
        renderMine(data.memberships || []);
        handleInviteTarget(data);
        return data;
      });
    }
    function refresh() {
      var refreshButton = el('cgb-client-refresh');
      var oldText = refreshButton.textContent;
      refreshButton.disabled = true;
      refreshButton.textContent = '正在刷新…';
      load().then(function () {
        message('拼团信息已更新', true);
      }).catch(function (error) {
        message(error.message, false);
      }).finally(function () {
        refreshButton.disabled = false;
        refreshButton.textContent = oldText;
      });
    }

    root.querySelectorAll('[data-client-tab]').forEach(function (tabButton) {
      tabButton.onclick = function () { setTab(tabButton.getAttribute('data-client-tab')); };
    });
    el('cgb-mode-normal').onclick = function () { setMode('normal'); };
    el('cgb-mode-subsidy').onclick = function () { setMode('subsidy'); };
    createControl.onclick = createGroup;
    createControl.setAttribute('data-cgb-bound', '1');
    el('cgb-client-refresh').onclick = refresh;
    el('cgb-share-close').onclick = closeShare;
    el('cgb-share-modal').onclick = function (event) {
      if (event.target && event.target.getAttribute('data-share-close') === '1') closeShare();
    };
    el('cgb-share-copy').onclick = function () {
      if (!state.share) return;
      copyWithFeedback(state.share.share_url, el('cgb-share-url'), el('cgb-share-copy'), '复制上车链接');
    };
    el('cgb-share-download').onclick = downloadPoster;
    el('cgb-share-native').onclick = nativeShare;
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !el('cgb-share-modal').hidden) closeShare();
    });
    load().catch(function (error) { message(error.message, false); });
  }

  // 只在 V10/Vue 壳层完成挂载后初始化，避免先绑定旧 DOM 再被 Vue 替换。
  window.addEventListener('cgb:client-shell-mounted', boot);
  if (window.__cgbMounted) setTimeout(boot, 0);
})();
