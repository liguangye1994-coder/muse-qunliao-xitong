/* 群聊前端轮询（assets/app.js）—— 只拉当前房间的消息，不含任何 token */
(function () {
  var list = document.getElementById('msg-list');
  var form = document.getElementById('send-form');
  var input = document.getElementById('msg-input');
  if (!list) return;

  var lastId = parseInt(list.getAttribute('data-last-id') || '0', 10);
  var isAdmin = list.getAttribute('data-is-admin') === '1';
  var meId = parseInt(list.getAttribute('data-me-id') || '0', 10);
  var meName = list.getAttribute('data-me-name') || '';
  var csrf = form ? form.getAttribute('data-csrf') : '';
  // 当前房间（旁聊）：1 = 主群，其他是各旁聊的 id。轮询/发送/翻记录都带它，
  // 切房间时换掉它再拉该房间的消息。
  var currentRoom = parseInt(list.getAttribute('data-room-id') || '1', 10);
  var currentRoomName = '';
  var roomTitle = document.getElementById('room-title');
  if (roomTitle) currentRoomName = roomTitle.textContent;

  // 未读蓝点：哪间有你还没看的消息，那间左上角显示蓝色小圆点（像 Muse 那样）。
  // markRead 把某间标已读（调 mark_read.php，记到 last_id）；只在 last_id
  // 变大时才调，免得每 3 秒轮询都发一次。updateUnreadDots 按轮询回的
  // rooms_unread 刷新各间的蓝点（当前房间在前台时永远不显示，你正在看它；
  // 标签页在后台时也显示，因为你没在看）。“看过”认的是标签页在前台，
  // 不是页面开着：后台时不标已读，切回来才标（见 markRead / visibilitychange）。
  // 浏览器标签页标题：有没看的消息时，标题后面挂未读条数（“群聊 - 群聊（3）”），
  // 看完就恢复原样。总数只算没归档的房间（归档的只在“已归档”区里亮蓝点），
  // 当前房间平时不算（你正在看它）；标签页在后台时也算。rooms_unread 回的是每间的未读条数。
  var baseTitle = document.title;
  var unreadTotal = parseInt(list.getAttribute('data-unread-total') || '0', 10) || 0;
  function updateDocTitle(total) {
    unreadTotal = total;
    document.title = total > 0 ? baseTitle + '（' + total + '）' : baseTitle;
  }
  updateDocTitle(unreadTotal);
  var lastMarkedRead = {};
  function markRead(roomId, lastId) {
    if (!roomId || !lastId) return;
    if (document.hidden) return; // 标签页在后台：人不看，不算已读，等切回来再标
    if (lastMarkedRead[roomId] && lastMarkedRead[roomId] >= lastId) return;
    lastMarkedRead[roomId] = lastId;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'mark_read.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.send('room_id=' + roomId + '&last_id=' + lastId + '&csrf_token=' + encodeURIComponent(csrf));
  }
  // 标签页切回前台才算“看过了”：切回来时把当前房间标已读、刷新一轮未读
  //（标题数和蓝点一起清掉）。markRead 自己也认后台（上面那行），双保险。
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      setRoomDot(currentRoom, false);
      markRead(currentRoom, lastId);
      poll(true);
    }
  });
  function setRoomDot(roomId, show) {
    var item = document.querySelector('.room-item[data-room-id="' + roomId + '"]');
    if (!item) return;
    var dot = item.querySelector('.unread-dot');
    if (show) {
      if (!dot) {
        dot = document.createElement('span');
        dot.className = 'unread-dot';
        dot.setAttribute('aria-label', '有未读消息');
        item.insertBefore(dot, item.firstChild);
      }
    } else if (dot) {
      dot.parentNode.removeChild(dot);
    }
  }
  function isRoomArchived(roomId) {
    var item = document.querySelector('.room-item[data-room-id="' + roomId + '"]');
    return !!(item && item.closest && item.closest('#archived-list'));
  }
  function setArchivedDot(show) {
    var dot = document.getElementById('archived-dot');
    if (dot) dot.style.display = show ? '' : 'none';
  }
  function updateUnreadDots(map) {
    if (!map) return;
    var total = 0;
    var anyArchivedUnread = false;
    Object.keys(map).forEach(function (rid) {
      var id = parseInt(rid, 10);
      var count = parseInt(map[rid], 10) || 0;
      if (id === currentRoom) {
        // 标签页在后台：当前房间你也没在看，有未读就亮蓝点、算进标题总数
        //（归档的房间不算进标题，只亮“已归档”区的蓝点）；在前台就永远不显示。
        if (document.hidden && count > 0) {
          setRoomDot(id, true);
          if (isRoomArchived(id)) anyArchivedUnread = true; else total += count;
        } else {
          setRoomDot(id, false);
        }
        return;
      }
      setRoomDot(id, count > 0);
      if (isRoomArchived(id)) {
        if (count > 0) anyArchivedUnread = true;
      } else {
        total += count;
      }
    });
    updateDocTitle(total);
    setArchivedDot(anyArchivedUnread);
  }

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // 文件名里常有中文：tmpfile.link 直链把原文件名放在路径里（…/转刀游戏-….zip、
  // …/伽罗玩法-复刻-10秒竖屏.mp4），以前链接匹配排除中文，URL 在第一个汉字处
  // 就被截断——后缀丢了，图片/视频不出预览，点开还是断链。所以 URL 里允许汉字，
  // 全角标点（（）、，。；：！？）和空格仍不算链接的一部分；匹配完再把末尾粘上
  // 的中文说明剥下来（.zip到明天 → .zip + 到明天）。
  // 图片 / 视频链接 inline 预览（点图灯箱放大）；文件链接（压缩包、文档等）只
  // 显示文件名，点文件名直接下载；普通网页链接仍显示整条地址。先转义再处理，不插原始 HTML。
  var FILE_EXTS = 'zip|rar|7z|tar|gz|apk|pdf|doc|docx|xls|xlsx|ppt|pptx|txt|md|epub|mobi|exe|dmg|iso|mp3|wav|ogg|flac';
  // 从链接里取文件名：tmpfile.link / tempfilestorage.link 的直链一定是文件，
  // 其他链接只有带常见文件后缀时才算文件。取不到文件名就返回 ''，走原来的整条链接显示。
  function fileNameOf(url) {
    var hm = url.match(/^https?:\/\/([^\/:?#]+)/i);
    var host = hm ? hm[1].toLowerCase() : '';
    var path = url.split('?')[0].split('#')[0];
    var isFileHost = /(^|\.)tmpfile\.link$/.test(host) || /(^|\.)tempfilestorage\.link$/.test(host);
    var isFileExt = new RegExp('\\.(' + FILE_EXTS + ')$', 'i').test(path);
    if (!isFileHost && !isFileExt) return '';
    var seg = path.split('/').pop() || '';
    seg = seg.replace(/&amp;/g, '&');
    try { seg = decodeURIComponent(seg); } catch (e) { /* 原样用 */ }
    if (!seg) return '';
    if (!isFileHost && seg.indexOf('.') < 0) return '';
    return seg;
  }
  function linkify(s) {
    return esc(s).replace(/https?:\/\/[^\s<>"'\u2000-\u206F\u3000-\u303F\uFF00-\uFFEF]+/g, function (url) {
      var trail = '';
      var m = url.match(/^(.*?)([。，、；：！？）】》.,!?;:)\]}]+)$/);
      if (m) { url = m[1]; trail = m[2]; }
      if (!url) return m[0];
      var path = url.split('?')[0].split('#')[0].toLowerCase();
      // 末尾粘了中文说明、且路径没有已知文件后缀时，把末尾那串汉字剥下来还给正文。
      if (!/\.(jpg|jpeg|png|gif|webp|bmp|mp4|webm|mov|ogv)$/.test(path) && !(new RegExp('\\.(' + FILE_EXTS + ')$').test(path))) {
        var cm = url.match(/^(.*?)([\u3400-\u9FFF\uF900-\uFAFF]+)$/);
        if (cm && cm[1].length > 10) { url = cm[1]; trail = cm[2] + trail; path = url.split('?')[0].split('#')[0].toLowerCase(); }
      }
      if (/\.(jpg|jpeg|png|gif|webp|bmp)$/.test(path)) {
        return '<a href="' + url + '" target="_blank" rel="noopener"><img src="' + url + '" alt="图片" loading="lazy"></a>' + trail;
      }
      if (/\.(mp4|webm|mov|ogv)$/.test(path)) {
        return '<span class="media-wrap"><video controls preload="metadata" src="' + url + '"></video><button type="button" class="media-expand" title="放大" aria-label="放大视频">⛶</button></span>' + trail;
      }
      var fname = fileNameOf(url);
      if (fname) {
        return '<a class="file-link" href="' + url + '" target="_blank" rel="noopener" download="' + esc(fname) + '" title="' + url + '">📎 ' + esc(fname) + '</a>' + trail;
      }
      return '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>' + trail;
    });
  }

  // 点图放大：图片默认只显示缩略图（最大 320px），点一下在全屏遮罩里看原图；
  // 视频点右上角 ⛶ 同样放大播。点空白处、点 × 或按 Esc 关闭，关视频时停掉播放。
  // 灯箱节点由 JS 建，不用改 index.php；委托挂在 #msg-list 上，首屏直出的
  // 消息和轮询新拉的消息都走同一套。
  var lightbox = null;
  function getLightbox() {
    if (lightbox) return lightbox;
    lightbox = document.createElement('div');
    lightbox.id = 'media-lightbox';
    lightbox.innerHTML = '<button type="button" class="lb-close" aria-label="关闭">×</button><a class="lb-open" href="#" target="_blank" rel="noopener">在新标签页打开原图</a>';
    document.body.appendChild(lightbox);
    lightbox.addEventListener('click', function (ev) {
      if (ev.target === lightbox || (ev.target.classList && ev.target.classList.contains('lb-close'))) closeLightbox();
    });
    return lightbox;
  }
  function openLightbox(url, isVideo) {
    var lb = getLightbox();
    var old = lb.querySelector('img, video');
    if (old) { if (old.tagName === 'VIDEO') old.pause(); lb.removeChild(old); }
    var el;
    if (isVideo) {
      el = document.createElement('video');
      el.src = url; el.controls = true; el.autoplay = true; el.setAttribute('preload', 'metadata');
    } else {
      el = document.createElement('img');
      el.src = url; el.alt = '图片';
    }
    var openLink = lb.querySelector('.lb-open');
    openLink.href = url;
    openLink.textContent = isVideo ? '在新标签页打开原视频' : '在新标签页打开原图';
    lb.insertBefore(el, openLink);
    lb.classList.add('open');
  }
  function closeLightbox() {
    if (!lightbox) return;
    var old = lightbox.querySelector('img, video');
    if (old) { if (old.tagName === 'VIDEO') old.pause(); lightbox.removeChild(old); }
    lightbox.classList.remove('open');
  }
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') closeLightbox();
  });
  list.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t) return;
    // 点视频右上角 ⛶：放大播（不拦视频本身的点播/暂停）。
    if (t.classList && t.classList.contains('media-expand')) {
      var wrap = t.parentElement;
      var v = wrap ? wrap.querySelector('video') : null;
      if (v) openLightbox(v.getAttribute('src') || v.currentSrc, true);
      return;
    }
    // 点图片：拦住外层 <a target="_blank"> 的新标签跳转，改在灯箱里看原图。
    if (t.tagName === 'IMG' && t.closest && t.closest('.msg .body')) {
      ev.preventDefault();
      openLightbox(t.getAttribute('src') || t.currentSrc, false);
    }
  });

  function replySnippet(text) {
    var s = String(text || '').split(/\r\n|\r|\n/)[0];
    if (s.length > 60) s = s.slice(0, 60) + '…';
    return s;
  }

  function renderOne(m) {
    var div = document.createElement('div');
    // 先审后发：自己发的 pending 消息标“待审核，仅自己可见”。
    // 群内直接审核：管理员看到别人的 pending 消息时，下面有通过 / 不通过按钮。
    var pending = (m.review_status === 'pending');
    var canReview = isAdmin && pending && !m.is_muse_reply;
    var isOwn = (parseInt(m.user_id, 10) === meId);
    // 头像字：AI 取名字末字，其他人取名字首字。
    var avChar = m.is_muse_reply ? String(m.username).slice(-1) : String(m.username).charAt(0);
    div.className = 'msg' + (m.is_muse_reply ? ' muse' : '') + (pending ? ' pending' : '') + (isOwn ? ' own' : '');
    div.setAttribute('data-id', m.id);
    var quote = '';
    if (m.reply_to_id && m.reply_username) {
      quote = '<div class="quote">↩ ' + esc(m.reply_username) + '：' + esc(replySnippet(m.reply_content)) + '</div>';
    }
    div.innerHTML = '<div class="msg-row"><div class="avatar">' + esc(avChar) + '</div><div class="msg-content">' +
      '<div class="meta"><b>' + esc(m.username) + '</b> · ' +
      esc(m.created_at) + (pending ? ' · <span class="review-tag">待审核' + (canReview ? '' : '，仅自己可见') + '</span>' : '') +
      ' <button type="button" class="reply-btn" data-id="' + m.id + '" data-user="' + esc(m.username) + '">↩ 回复</button></div>' +
      quote +
      '<div class="body">' + linkify(m.content) + '</div>' +
      renderReactions(m) +
      '</div></div>' +
      (canReview ? '<div class="review-actions"><button type="button" data-review="approve" data-id="' + m.id + '">通过</button> <button type="button" class="danger" data-review="reject" data-id="' + m.id + '">不通过</button></div>' : '');
    return div;
  }

  // 消息表情回应：每条消息下面一排小表情 chips（像 Muse 界面的“留下心情” / TG 的回复表情）。
  // m.reactions 是 fetch.php / 推送回的汇总：{emoji: {count, users, mine}}。
  // mine 是服务端按名字算的；推送来的没有 mine，前端按 users 里有没有自己的名字算。
  var REACTION_EMOJIS = ['👌', '👍', '❤️', '😂', '😮', '🙏', '👏', '🎉'];
  function reactionMine(info) {
    if (!info) return false;
    if (typeof info.mine !== 'undefined') return !!info.mine;
    if (meName && info.users) return info.users.indexOf(meName) !== -1;
    return false;
  }
  function renderReactions(m) {
    var html = '<div class="reactions" data-id="' + m.id + '">';
    var rs = m.reactions || {};
    Object.keys(rs).forEach(function (emo) {
      var info = rs[emo];
      var mine = reactionMine(info) ? ' mine' : '';
      var users = (info.users || []).join('、');
      html += '<button type="button" class="react-chip' + mine + '" data-id="' + m.id + '" data-reaction="' + esc(emo) + '" title="' + esc(users) + '">' + esc(emo) + ' ' + (parseInt(info.count, 10) || 0) + '</button>';
    });
    html += '<button type="button" class="react-add" data-id="' + m.id + '" title="留个表情" aria-label="留个表情">😊</button></div>';
    return html;
  }
  // 刷新一条消息的 chips（轮询 / 推送共用）：整块重绘，自己的那颗带 mine 高亮。
  function applyReactions(messageId, reactions) {
    var row = list.querySelector('[data-id="' + messageId + '"]');
    if (!row) return;
    var box = row.querySelector('.reactions');
    if (!box) return;
    var tmp = document.createElement('div');
    tmp.innerHTML = renderReactions({ id: messageId, reactions: reactions || {} });
    var fresh = tmp.firstChild;
    if (fresh) box.parentNode.replaceChild(fresh, box);
  }
  // 点按切换：点一下留、再点一下取消；成功后按回的汇总刷新 chips。
  function toggleReaction(messageId, reaction) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'reactions.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status !== 200) return;
      try {
        var res = JSON.parse(xhr.responseText);
        if (res && res.ok && res.message_id) applyReactions(res.message_id, res.reactions);
      } catch (e) { /* 失败就等下一次轮询，不弹错 */ }
    };
    xhr.send('message_id=' + encodeURIComponent(messageId) +
      '&reaction=' + encodeURIComponent(reaction) +
      '&csrf_token=' + encodeURIComponent(csrf));
  }
  // 表情选择器：点某条消息的 😊 弹出 8 个表情，点一个就留（再点取消）。
  // 一个共享的浮层，点别处或按 Esc 收起。
  var reactPicker = null;
  var reactPickerFor = 0;
  function getReactPicker() {
    if (reactPicker) return reactPicker;
    reactPicker = document.createElement('div');
    reactPicker.id = 'react-picker';
    reactPicker.style.display = 'none';
    REACTION_EMOJIS.forEach(function (emo) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'react-pick';
      b.textContent = emo;
      b.addEventListener('click', function (ev) {
        ev.stopPropagation();
        var mid = reactPickerFor;
        closeReactPicker();
        if (mid) toggleReaction(mid, emo);
      });
      reactPicker.appendChild(b);
    });
    document.body.appendChild(reactPicker);
    return reactPicker;
  }
  function openReactPicker(mid, anchor) {
    var p = getReactPicker();
    reactPickerFor = mid;
    p.style.display = 'flex';
    var r = anchor.getBoundingClientRect();
    var top = r.bottom + 6;
    var left = Math.max(8, Math.min(r.left, window.innerWidth - 330));
    p.style.top = top + 'px';
    p.style.left = left + 'px';
  }
  function closeReactPicker() {
    if (reactPicker) reactPicker.style.display = 'none';
    reactPickerFor = 0;
  }
  document.addEventListener('click', function (ev) {
    if (reactPicker && reactPicker.style.display !== 'none') {
      var t = ev.target;
      var inside = reactPicker.contains(t);
      var isAdd = t.classList && t.classList.contains('react-add');
      if (!inside && !isAdd) closeReactPicker();
    }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') closeReactPicker();
  });
  // 表情点按：点 chips 切换自己的那个表情；点 😊 弹出选择器。
  list.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.classList) return;
    if (t.classList.contains('react-chip')) {
      var mid = parseInt(t.getAttribute('data-id') || '0', 10);
      var emo = t.getAttribute('data-reaction') || '';
      if (mid && emo) toggleReaction(mid, emo);
      return;
    }
    if (t.classList.contains('react-add')) {
      var mid2 = parseInt(t.getAttribute('data-id') || '0', 10);
      if (!mid2) return;
      if (reactPicker && reactPicker.style.display !== 'none' && reactPickerFor === mid2) {
        closeReactPicker();
      } else {
        openReactPicker(mid2, t);
      }
    }
  });

  // 群内直接审核：点通过 / 不通过，调 review.php，成功后更新这条消息的显示。
  list.addEventListener('click', function (ev) {
    var btn = ev.target;
    if (!btn || !btn.getAttribute('data-review')) return;
    var action = btn.getAttribute('data-review');
    var mid = btn.getAttribute('data-id');
    if (action === 'reject' && !window.confirm('确定不通过这条消息吗？群里不会显示它。')) return;
    btn.disabled = true;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'review.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      btn.disabled = false;
      // 以前这里失败时一声不吭，点了像没点、看着像卡住；现在把原因弹出来。
      function reviewFailed(defMsg) {
        var msg = defMsg;
        try {
          var e = JSON.parse(xhr.responseText);
          if (e && e.error) msg = '审核没成功：' + e.error;
        } catch (err) { /* 取不到原因就用默认提示 */ }
        alert(msg);
      }
      if (xhr.status !== 200) { reviewFailed('审核没成功，请刷新页面再试。'); return; }
      try {
        var res = JSON.parse(xhr.responseText);
        if (!res || !res.ok) { reviewFailed('审核没成功，请刷新页面再试。'); return; }
        var row = list.querySelector('[data-id="' + mid + '"]');
        if (!row) return;
        if (res.review_status === 'rejected') {
          row.parentNode.removeChild(row); // 不通过：管理员这里也不再显示
        } else {
          row.classList.remove('pending');
          var tag = row.querySelector('.review-tag');
          if (tag) tag.parentNode.removeChild(tag);
          var acts = row.querySelector('.review-actions');
          if (acts) acts.parentNode.removeChild(acts);
        }
      } catch (e) { reviewFailed('审核没成功，请刷新页面再试。'); }
    };
    xhr.send('message_id=' + encodeURIComponent(mid) +
      '&action=' + encodeURIComponent(action) +
      '&csrf_token=' + encodeURIComponent(csrf));
  });

  // 翻记录不再被拽到底部：只有本来就贴在底部（或刚发完消息）时才跟随到底部；
  // 往上翻着看记录时来了新消息，不动滚动位置，只显示“有新消息”按钮，点它再下去。
  var pill = document.getElementById('new-msg-pill');
  var newWhileAway = 0;
  var scrollAfterSend = false;

  function nearBottom() {
    return (list.scrollHeight - list.scrollTop - list.clientHeight) < 100;
  }

  function showPill() {
    if (!pill) return;
    pill.textContent = '↓ 有新消息' + (newWhileAway > 1 ? '（' + newWhileAway + ' 条）' : '');
    pill.style.display = 'block';
  }

  function hidePill() {
    newWhileAway = 0;
    if (pill) pill.style.display = 'none';
  }

  if (pill) {
    pill.addEventListener('click', function () {
      list.scrollTop = list.scrollHeight;
      hidePill();
      poll(true); // 回到底部：把往上翻时收掉的新消息拉回来
    });
  }

  // 消息长度（照 Muse 聊天界面的做法）：首屏只拉最近 50 条，往上翻到顶
  // 再加载更早的 50 条；页上最多留 200 条，多了就把看不见的那头收掉，
  // 不是删数据——往上翻还能再加载回来。超过 200 条不收，翻久了页面会卡。
  var PAGE_MSGS = 50;
  var MAX_MSGS = 200;
  var loadingOlder = false;
  var hasOlder = true;

  // 切房间（旁聊）：点边栏里的会话就切过去，不刷新整页。
  // 先把列表清空、把水位和已加载 id 重置，再调 fetch.php?initial=1 拉该房间
  // 最近 50 条重绘；标题、输入框提示、边栏高亮一起换。切走时正在输入的
  // 回复引用也清掉（引用的是上一个房间的消息）。
  function setActiveRoom(roomId) {
    var items = document.querySelectorAll('.room-item');
    Array.prototype.forEach.call(items, function (el) {
      if (parseInt(el.getAttribute('data-room-id') || '0', 10) === roomId) {
        el.classList.add('active');
      } else {
        el.classList.remove('active');
      }
    });
  }
  function loadRoom(roomId, roomName) {
    if (roomId === currentRoom) return;
    currentRoom = roomId;
    currentRoomName = roomName || currentRoomName;
    if (roomTitle) roomTitle.textContent = currentRoomName;
    if (input) input.placeholder = '发消息到“' + currentRoomName + '”';
    if (form) form.setAttribute('data-room-id', String(roomId));
    setActiveRoom(roomId);
    clearReply();
    if (typeof closeMention === 'function') closeMention();
    // 清空列表和去重表，重新按新房间的水位走。
    list.innerHTML = '<div id="older-status" style="display:none;"></div>';
    olderStatus = document.getElementById('older-status');
    loadedIds = {};
    lastId = 0;
    hasOlder = true;
    loadingOlder = false;
    newWhileAway = 0;
    hidePill();
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'fetch.php?room_id=' + roomId + '&initial=1', true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status === 401) { location.reload(); return; }
      if (xhr.status !== 200) return;
      try {
        var res = JSON.parse(xhr.responseText);
        if (!res || !res.ok || !res.messages) return;
        if (typeof res.is_admin !== 'undefined') isAdmin = !!res.is_admin;
        if (typeof res.me !== 'undefined') meId = parseInt(res.me, 10) || meId;
        res.messages.forEach(function (m) {
          if (m.review_status === 'rejected') return;
          if (parseInt(m.id, 10) > lastId) lastId = parseInt(m.id, 10);
          if (loadedIds[m.id]) return;
          loadedIds[m.id] = true;
          list.appendChild(renderOne(m));
        });
        if (res.messages.length < PAGE_MSGS) {
          hasOlder = false;
          setOlderStatus('—— 前面没有更多消息了 ——');
        } else {
          setOlderStatus('');
        }
        list.scrollTop = list.scrollHeight;
        // 切过来就等于看过了：把这间的蓝点去掉、标已读；顺便按回的
        // rooms_unread 刷新其他房间的蓝点。
        setRoomDot(roomId, false);
        markRead(roomId, lastId);
        if (res.rooms_unread) updateUnreadDots(res.rooms_unread);
      } catch (e) { /* 解析失败就等下一次轮询，不弹错 */ }
    };
    xhr.send();
    // 手机上切完房间把边栏收起来，露出对话。
    closeSidebar();
  }

  // 已拿到过的消息 id（包括从页面上收掉的）：平时轮询按这个去重，不按
  // 页面上有没有，不然收掉的旧消息会被轮询又加回来、刚翻到的旧记录又被顶掉。
  var loadedIds = {};
  Array.prototype.forEach.call(list.querySelectorAll('.msg'), function (el) {
    var id = el.getAttribute('data-id');
    if (id) loadedIds[id] = true;
  });
  if (list.querySelectorAll('.msg').length < PAGE_MSGS) hasOlder = false;

  function msgEls() {
    return list.querySelectorAll('.msg');
  }

  function firstMsgId() {
    var els = msgEls();
    return els.length ? parseInt(els[0].getAttribute('data-id') || '0', 10) : 0;
  }

  // 从页面上方收掉最旧的消息，只留 MAX_MSGS 条；收的时候保持滚动位置不动
  // （收之前记下 scrollTop，收完按收掉的高度减回去，贴底时正好落回底部）。
  function pruneTop() {
    var els = msgEls();
    var over = els.length - MAX_MSGS;
    if (over <= 0) return;
    var oldTop = list.scrollTop;
    var removedH = 0;
    for (var i = 0; i < over; i++) {
      removedH += els[i].offsetHeight + 12; // 12 是 #msg-list 的 gap
      list.removeChild(els[i]);
    }
    list.scrollTop = Math.max(0, oldTop - removedH);
  }

  // 从页面下方收掉最新的消息（只在往上翻加载更早记录、超出上限时用；
  // 用户在上面看旧记录，下面的收掉看不见；回到底部时 poll(true) 会再拉回来）。
  function pruneBottom() {
    var els = msgEls();
    var over = els.length - MAX_MSGS;
    if (over <= 0) return;
    for (var i = 0; i < over; i++) {
      list.removeChild(els[els.length - 1 - i]);
    }
  }

  var olderStatus = document.getElementById('older-status');
  function setOlderStatus(text) {
    if (!olderStatus) return;
    if (text) {
      olderStatus.textContent = text;
      olderStatus.style.display = 'block';
    } else {
      olderStatus.style.display = 'none';
    }
  }

  function loadOlder() {
    if (loadingOlder || !hasOlder) return;
    var beforeId = firstMsgId();
    if (!beforeId) return;
    loadingOlder = true;
    setOlderStatus('正在加载更早的消息…');
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'fetch.php?room_id=' + currentRoom + '&before_id=' + beforeId, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      loadingOlder = false;
      if (xhr.status !== 200) { setOlderStatus(''); return; }
      try {
        var res = JSON.parse(xhr.responseText);
        if (!res || !res.ok || !res.messages) { setOlderStatus(''); return; }
        if (res.messages.length < PAGE_MSGS) {
          hasOlder = false;
          setOlderStatus('—— 前面没有更多消息了 ——');
        } else {
          setOlderStatus('');
        }
        if (!res.messages.length) return;
        var oldH = list.scrollHeight;
        var oldTop = list.scrollTop;
        var frag = document.createDocumentFragment();
        res.messages.forEach(function (m) {
          if (m.review_status === 'rejected') return; // 已不通过的不显示
          if (loadedIds[m.id]) return;
          loadedIds[m.id] = true;
          frag.appendChild(renderOne(m));
        });
        if (!frag.childNodes.length) return;
        var anchor = olderStatus ? olderStatus.nextSibling : list.firstChild;
        list.insertBefore(frag, anchor);
        // 上面加了内容，把滚动位置往下顶回原来的地方，不然页面会跳。
        list.scrollTop = oldTop + (list.scrollHeight - oldH);
        pruneBottom();
      } catch (e) { setOlderStatus(''); }
    };
    xhr.send();
  }

  var wasNear = nearBottom();
  list.addEventListener('scroll', function () {
    var near = nearBottom();
    if (near) {
      hidePill();
      if (!wasNear) poll(true); // 刚回到贴底：把收掉的新消息拉回来
    }
    wasNear = near;
    if (list.scrollTop < 80) loadOlder();
  });

  // 刚打开页面时先到底部看最新消息，之后再翻记录就不再自动拽下去。
  list.scrollTop = list.scrollHeight;
  // 刚打开就等于看过了当前房间：标已读（下次打开/换设备也不再出蓝点）。
  markRead(currentRoom, lastId);

  // 把一批新消息追加到列表（轮询和 WebSocket 推送共用同一份逻辑，
  // 保证两种途径来的消息显示得一模一样）。wasNearBottom 是调用时
  // 是不是贴在底部；force 是不是“刚回到底部”的模式（见 poll）；返回追加的条数。
  function applyMessages(messages, wasNearBottom, force) {
    var appended = 0;
    messages.forEach(function (m) {
      if (parseInt(m.id, 10) > lastId) lastId = parseInt(m.id, 10);
      var existing = list.querySelector('[data-id="' + m.id + '"]');
      // force 模式（刚回到底部时）按页面上有没有去重，把收掉的新消息拉回来；
      // 平时按 loadedIds 去重，收掉的旧消息不再加回来。
      var known = force ? !!existing : !!loadedIds[m.id];
      if (known) {
        if (existing) {
          applyReview(m.id, m.review_status);
          if (m.reactions) applyReactions(m.id, m.reactions);
        }
        return;
      }
      if (m.review_status === 'rejected') return; // 已不通过的消息不追加显示
      // 跳过比页上第一条还旧的消息：那是往上翻加载（loadOlder）的范围，
      // 不是轮询的范围。不跳的话，首屏只剩 50 条时，轮询会把窗口里更早
      // 的 50 条追加到最下面，顺序全乱（旧消息跑到新消息下面）。
      if (parseInt(m.id, 10) < firstMsgId()) return;
      list.appendChild(renderOne(m));
      loadedIds[m.id] = true;
      appended++;
    });
    if (appended) {
      pruneTop();
      if (wasNearBottom) {
        list.scrollTop = list.scrollHeight;
        hidePill();
      } else {
        newWhileAway += appended;
        showPill();
      }
    }
    // 当前房间的新消息：标签页在前台、贴到底部了，等于正在看，标已读；
    // 标签页在后台，你根本没看见，不标已读，标题数 +N、当前房间也亮蓝点，
    // 等切回来再清（下次轮询按服务端重算）。
    if (appended > 0) {
      if (document.hidden) {
        setRoomDot(currentRoom, true);
        updateDocTitle(unreadTotal + appended);
      } else if (wasNearBottom) {
        markRead(currentRoom, lastId);
      }
    }
    scrollAfterSend = false;
    return appended;
  }

  // 审核结果同步：一条消息被通过 / 不通过后，更新页上那行的显示
  // （通过：去掉待审核标记和按钮；不通过：直接删掉那行）。轮询和推送共用。
  function applyReview(messageId, reviewStatus) {
    var row = list.querySelector('[data-id="' + messageId + '"]');
    if (!row) return;
    if (reviewStatus === 'rejected') {
      // 别处点了不通过：页上还挂着的那行直接删掉，不然再点就报“已审过”，看着像卡住。
      row.parentNode.removeChild(row);
    } else if (reviewStatus === 'approved') {
      // 已在页上的 pending 消息在别处被通过了，这里同步去掉待审核标记和按钮。
      row.classList.remove('pending');
      var tag = row.querySelector('.review-tag');
      if (tag) tag.parentNode.removeChild(tag);
      var acts = row.querySelector('.review-actions');
      if (acts) acts.parentNode.removeChild(acts);
    }
  }

  function poll(force) {
    var wasNearBottom = nearBottom() || scrollAfterSend;
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'fetch.php?room_id=' + currentRoom + '&since_id=' + lastId, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      // 会话过期（401）：直接刷新页面，index.php 会把人送到登录页，
      // 登录后就能看到最新消息；不然页会悄悄停在昨晚、看着像卡住。
      if (xhr.status === 401) { location.reload(); return; }
      if (xhr.status !== 200) return;
      try {
        var res = JSON.parse(xhr.responseText);
        if (!res || !res.ok || !res.messages) return;
        if (typeof res.is_admin !== 'undefined') isAdmin = !!res.is_admin;
        if (typeof res.me !== 'undefined') meId = parseInt(res.me, 10) || meId;
        applyMessages(res.messages, wasNearBottom, force);
        // 各房间的未读蓝点：轮询每轮都回 rooms_unread，按它刷新边栏。
        if (res.rooms_unread) updateUnreadDots(res.rooms_unread);
      } catch (e) { /* 解析失败就等下一次轮询，不弹错 */ }
    };
    xhr.send();
  }

  // 实时推送（WebSocket）：有新消息立刻显示，不用等轮询。
  // 推不动 / 没连上时自动回落到 3 秒轮询，群聊不会断。
  var POLL_FAST = 3000;   // WebSocket 没连上时的轮询间隔（和原来一样）
  var POLL_SLOW = 30000;  // WebSocket 连上后的轮询间隔（只当兜底，补漏网消息）
  var pollTimer = null;
  var ws = null;
  var wsRetryDelay = 1000;
  var wsRetryTimer = null;

  function setPollInterval(ms) {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    pollTimer = setInterval(poll, ms);
  }

  function wsUrl() {
    var proto = (location.protocol === 'https:') ? 'wss://' : 'ws://';
    // 固定走 /chat/ws：登录态 Cookie 的 path 是 /chat/，只有 /chat/ 下的地址才会带上它。
    // 不管页面是在根目录还是 /chat/ 打开，都走这里。
    return proto + location.host + '/chat/ws';
  }

  function scheduleWsRetry() {
    if (wsRetryTimer) return;
    wsRetryTimer = setTimeout(function () {
      wsRetryTimer = null;
      connectWs();
    }, wsRetryDelay);
    wsRetryDelay = Math.min(wsRetryDelay * 2, 30000);
  }

  function connectWs() {
    if (!('WebSocket' in window)) return; // 不支持 WebSocket：只用轮询
    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;
    var url;
    try {
      url = wsUrl();
    } catch (e) {
      return;
    }
    try {
      ws = new WebSocket(url);
    } catch (e) {
      scheduleWsRetry();
      return;
    }
    ws.onopen = function () {
      wsRetryDelay = 1000;
      // 订阅全部房间：本房间的消息直接渲染，其他房间的消息只亮蓝点。
      try { ws.send(JSON.stringify({ type: 'subscribe', rooms: '*' })); } catch (e) {}
      setPollInterval(POLL_SLOW); // 连上后轮询降到 30 秒，只当兜底
    };
    ws.onmessage = function (ev) {
      var data;
      try { data = JSON.parse(ev.data); } catch (e) { return; }
      if (!data || !data.type) return;
      var rid = parseInt(data.room_id, 10) || 0;
      if (!rid) return;
      if (data.type === 'message' && data.message) {
        if (rid === currentRoom) {
          applyMessages([data.message], nearBottom() || scrollAfterSend, false);
        } else {
          setRoomDot(rid, true); // 别的房间有新消息：亮蓝点
          if (isRoomArchived(rid)) {
            setArchivedDot(true); // 归档的房间：只亮“已归档”区的蓝点，不算进标题总数
          } else {
            updateDocTitle(unreadTotal + 1); // 标题未读数先 +1，下次轮询按服务端重算
          }
        }
      } else if (data.type === 'reaction' && data.message_id) {
        // 表情回应：只刷新当前房间页上那条的 chips；别的房间不用动（切过去时轮询会同步）。
        if (rid === currentRoom) applyReactions(data.message_id, data.reactions);
      } else if (data.type === 'review' && data.message_id) {
        // 审核结果：只影响当前房间页上的那行；别的房间不用动（切过去时轮询会同步）。
        if (rid === currentRoom) applyReview(data.message_id, data.review_status);
      }
    };
    ws.onclose = function () {
      ws = null;
      setPollInterval(POLL_FAST); // 断了就回 3 秒轮询
      scheduleWsRetry();
    };
    ws.onerror = function () {
      try { ws.close(); } catch (e) {} // onerror 后一般会跟一个 onclose，收尾放 onclose 里
    };
  }

  // 默认先 3 秒轮询；WebSocket 连上后降到 30 秒兜底，断了再回 3 秒。
  setPollInterval(POLL_FAST);
  connectWs();

  // @ 提醒：输入 @ 弹出配置好的 Muse 名单（window.MUSE_NAMES，默认助手），
  // 点一下或上下键+回车/Tab 插入“@名字 ”。名单只放 Muse，不放普通成员；
  // 名单改动只改服务端配置，不用改这段代码。
  var museNames = (window.MUSE_NAMES && Array.isArray(window.MUSE_NAMES) && window.MUSE_NAMES.length) ? window.MUSE_NAMES : ['助手'];
  var mentionBox = null;
  var mentionList = [];
  var mentionSel = -1;
  var mentionAt = -1; // 输入框里当前这个 @ 的下标

  function closeMention() {
    if (mentionBox) mentionBox.style.display = 'none';
    mentionList = [];
    mentionSel = -1;
    mentionAt = -1;
  }

  function renderMention() {
    if (!mentionBox) {
      mentionBox = document.createElement('div');
      mentionBox.id = 'mention-list';
      mentionBox.style.display = 'none';
      if (form) form.appendChild(mentionBox);
    }
    mentionBox.innerHTML = '';
    mentionList.forEach(function (name, i) {
      var d = document.createElement('div');
      d.className = 'mention-item' + (i === mentionSel ? ' selected' : '');
      d.innerHTML = '<b>@</b>' + esc(name);
      // mousedown 比 blur 先触发，点下去时输入框还没失焦，能顺利插入。
      d.addEventListener('mousedown', function (ev) {
        ev.preventDefault();
        pickMention(name);
      });
      mentionBox.appendChild(d);
    });
    mentionBox.style.display = mentionList.length ? 'block' : 'none';
  }

  function updateMention() {
    if (!input) { closeMention(); return; }
    var caret = input.selectionStart;
    if (caret === null || caret === undefined) { closeMention(); return; }
    var before = input.value.slice(0, caret);
    // @ 必须在开头或空白后，避免邮箱之类的 a@b 误触发。
    var m = before.match(/(?:^|\s)@([^\s@]*)$/);
    if (!m) { closeMention(); return; }
    var q = m[1];
    var list = museNames.filter(function (n) { return q === '' || n.indexOf(q) !== -1; });
    if (!list.length) { closeMention(); return; }
    mentionAt = caret - q.length - 1;
    mentionList = list;
    mentionSel = 0;
    renderMention();
  }

  function moveMention(dir) {
    if (!mentionList.length) return;
    mentionSel = (mentionSel + dir + mentionList.length) % mentionList.length;
    renderMention();
  }

  function pickMention(name) {
    if (!input || mentionAt < 0) { closeMention(); return; }
    var caret = input.selectionStart;
    var v = input.value;
    input.value = v.slice(0, mentionAt) + '@' + name + ' ' + v.slice(caret);
    var pos = mentionAt + name.length + 2;
    try { input.setSelectionRange(pos, pos); } catch (e) { /* 旧浏览器没有也无妨 */ }
    closeMention();
    input.focus();
    if (typeof autoGrow === 'function') autoGrow();
  }

  // @ 按钮（输入框左侧 ＋ 旁边）：点它在光标处插一个 @，弹出 Muse 名单，
  // 点名字（或上下键+回车/Tab）就插“@名字 ”，和直接打 @ 一个效果。
  // mousedown 先 preventDefault，免得输入框失焦、150ms 后把刚弹出的名单关掉。
  // 再点一次就收起名单（开关切换）。
  // 注意：直接打 @ 时要求 @ 在开头或空格后（免得邮箱 a@b 误触发），但点按钮
  // 就是明确要 @ 人，所以不走 updateMention 的那条正则，直接把名单弹出来；
  // 选了就把刚插的这个 @ 换成“@名字 ”。
  var mentionBtn = document.getElementById('mention-btn');
  if (mentionBtn && input) {
    mentionBtn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
    mentionBtn.addEventListener('click', function () {
      if (mentionBox && mentionBox.style.display === 'block') { closeMention(); input.focus(); return; }
      input.focus();
      var pos = input.selectionStart;
      if (pos === null || pos === undefined) pos = input.value.length;
      var v = input.value;
      input.value = v.slice(0, pos) + '@' + v.slice(input.selectionEnd || pos);
      var npos = pos + 1;
      try { input.setSelectionRange(npos, npos); } catch (e) { /* 旧浏览器没有也无妨 */ }
      autoGrow();
      mentionAt = pos;
      mentionList = museNames.slice();
      mentionSel = 0;
      renderMention();
    });
  }

  // 输入框自适应高度：默认 1 行，随内容长到最多 8 行，超过 8 行框内滚动。
  // 8 行按行高 1.6 × 16px 算，再加上下内边距；改行高/字号时同步改 CSS 里的 max-height。
  var LINE_H = 26; // 一行约 26px（含行高）
  var MAX_LINES = 8;
  function autoGrow() {
    if (!input) return;
    input.style.height = 'auto';
    var maxH = LINE_H * MAX_LINES + 20; // 上下内边距各 10px
    var h = Math.min(input.scrollHeight, maxH);
    input.style.height = h + 'px';
    input.style.overflowY = (input.scrollHeight > maxH) ? 'auto' : 'hidden';
  }

  // 回复功能：点某条消息的“↩ 回复”，输入框上方出现引用条，发送时带 reply_to_id；
  // 发完或点 × 取消。引用内容取被引消息正文的第一行（最多 60 字）。
  var replyToId = 0;
  var replyBar = document.getElementById('reply-bar');
  var replyBarText = document.getElementById('reply-bar-text');
  var replyCancel = document.getElementById('reply-cancel');
  function setReply(id, user, snippet) {
    replyToId = id;
    if (replyBar && replyBarText) {
      replyBarText.textContent = '↩ 回复 ' + user + '：' + snippet;
      replyBar.style.display = 'flex';
    }
    if (input) { input.focus(); autoGrow(); }
  }
  function clearReply() {
    replyToId = 0;
    if (replyBar) replyBar.style.display = 'none';
  }
  if (replyCancel) replyCancel.addEventListener('click', function () { clearReply(); if (input) input.focus(); });
  list.addEventListener('click', function (ev) {
    var btn = ev.target;
    if (!btn || !btn.classList || !btn.classList.contains('reply-btn')) return;
    var mid = parseInt(btn.getAttribute('data-id') || '0', 10);
    var user = btn.getAttribute('data-user') || '';
    if (!mid) return;
    var row = list.querySelector('[data-id="' + mid + '"]');
    var snippet = '';
    if (row) {
      var body = row.querySelector('.body');
      if (body) snippet = replySnippet(body.textContent || '');
    }
    setReply(mid, user, snippet);
  });

  // 附件先挂后发（像 Muse 那样）：点 ＋ 选文件、把文件从电脑拖进来、
  // 粘贴图片，都是先在后台传到 upload.php（服务端转传 tmpfile.link，45MB 上限），
  // 拿到直链后挂在输入框上方当 chips，等用户写完文字、点发送才一起发出去——
  // 一条消息里是文字 + 附件直链各一行，不会像以前那样选完立刻单独发一条。
  var uploadBtn = document.getElementById('upload-btn');
  var fileInput = document.getElementById('file-input');
  var attachBar = document.getElementById('attach-bar');
  var dropOverlay = document.getElementById('drop-overlay');
  var sendBtn = form ? form.querySelector('button[type=submit]') : null;
  var pendingAttach = []; // {id, name, size, type, file, state: 'uploading'|'ready'|'error', progress, url, previewUrl, xhr}
  var attachSeq = 0;
  var MAX_ATTACH = 10;
  function fmtSize(n) {
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }
  function attachIcon(a) {
    if (a.type.indexOf('image/') === 0) return '🖼️';
    if (a.type.indexOf('video/') === 0) return '🎬';
    if (a.type.indexOf('audio/') === 0) return '🎵';
    return '📄';
  }
  function anyUploading() {
    return pendingAttach.some(function (a) { return a.state === 'uploading'; });
  }
  function updateSendState() {
    if (!sendBtn) return;
    var busy = anyUploading();
    sendBtn.disabled = busy;
    sendBtn.title = busy ? '附件上传中，传完再发' : '';
  }
  function renderAttach() {
    if (!attachBar) return;
    if (!pendingAttach.length) {
      attachBar.style.display = 'none';
      attachBar.innerHTML = '';
      updateSendState();
      return;
    }
    attachBar.style.display = 'flex';
    var html = '';
    pendingAttach.forEach(function (a) {
      var thumb = '';
      if (a.previewUrl) {
        thumb = '<img src="' + esc(a.previewUrl) + '" alt="">';
      } else {
        thumb = '<span class="attach-icon">' + attachIcon(a) + '</span>';
      }
      var state = '';
      if (a.state === 'uploading') state = '<span class="attach-state">上传中 ' + (a.progress || 0) + '%</span>';
      else if (a.state === 'error') state = '<span class="attach-state err">上传失败 <button type="button" class="attach-retry" data-id="' + a.id + '">重试</button></span>';
      html += '<span class="attach-chip' + (a.state === 'error' ? ' error' : '') + '">' + thumb
        + '<span class="attach-meta"><span class="attach-name">' + esc(a.name) + '</span>'
        + '<span class="attach-size">' + fmtSize(a.size) + '</span>' + state + '</span>'
        + '<button type="button" class="attach-remove" data-id="' + a.id + '" aria-label="移除附件">×</button></span>';
    });
    attachBar.innerHTML = html;
    updateSendState();
  }
  function removeAttach(id) {
    var idx = -1;
    for (var i = 0; i < pendingAttach.length; i++) {
      if (pendingAttach[i].id === id) { idx = i; break; }
    }
    if (idx < 0) return;
    var a = pendingAttach[idx];
    if (a.xhr) { try { a.xhr.abort(); } catch (e) {} }
    if (a.previewUrl && a.previewUrl.indexOf('blob:') === 0) {
      try { URL.revokeObjectURL(a.previewUrl); } catch (e) {}
    }
    pendingAttach.splice(idx, 1);
    renderAttach();
  }
  function clearAttach() {
    pendingAttach.forEach(function (a) {
      if (a.previewUrl && a.previewUrl.indexOf('blob:') === 0) {
        try { URL.revokeObjectURL(a.previewUrl); } catch (e) {}
      }
    });
    pendingAttach = [];
    renderAttach();
  }
  function uploadAttach(a) {
    var fd = new FormData();
    fd.append('file', a.file);
    fd.append('csrf_token', csrf);
    var xhr = new XMLHttpRequest();
    a.xhr = xhr;
    xhr.open('POST', 'upload.php', true);
    if (xhr.upload) {
      xhr.upload.onprogress = function (ev) {
        if (ev.lengthComputable) {
          a.progress = Math.round(ev.loaded * 100 / ev.total);
          renderAttach();
        }
      };
    }
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      // 中途被移除了就不管了（abort 也会走到这里，status 是 0）。
      if (pendingAttach.indexOf(a) < 0) return;
      try {
        var res = JSON.parse(xhr.responseText);
        if (xhr.status === 200 && res && res.ok && res.url) {
          a.state = 'ready';
          a.url = res.url;
        } else {
          a.state = 'error';
        }
      } catch (e) {
        a.state = 'error';
      }
      renderAttach();
    };
    xhr.send(fd);
  }
  function addFiles(files) {
    if (!files || !files.length) return;
    for (var i = 0; i < files.length; i++) {
      if (pendingAttach.length >= MAX_ATTACH) { alert('一次最多挂 ' + MAX_ATTACH + ' 个附件'); break; }
      var f = files[i];
      if (f.size > 45000000) { alert('“' + f.name + '”太大，上限 45MB'); continue; }
      if (f.size <= 0) continue;
      var a = {
        id: ++attachSeq,
        name: f.name || '文件',
        size: f.size,
        type: f.type || '',
        file: f,
        state: 'uploading',
        progress: 0,
        url: '',
        previewUrl: '',
        xhr: null
      };
      if (a.type.indexOf('image/') === 0) {
        try { a.previewUrl = URL.createObjectURL(f); } catch (e) {}
      }
      pendingAttach.push(a);
      renderAttach();
      uploadAttach(a);
    }
  }
  if (attachBar) {
    attachBar.addEventListener('click', function (ev) {
      var t = ev.target;
      if (!t || !t.getAttribute) return;
      var id = parseInt(t.getAttribute('data-id') || '0', 10);
      if (!id) return;
      if (t.classList.contains('attach-remove')) {
        removeAttach(id);
      } else if (t.classList.contains('attach-retry')) {
        var a = null;
        for (var i = 0; i < pendingAttach.length; i++) {
          if (pendingAttach[i].id === id) { a = pendingAttach[i]; break; }
        }
        if (a && a.file) {
          a.state = 'uploading';
          a.progress = 0;
          renderAttach();
          uploadAttach(a);
        }
      }
    });
  }
  if (uploadBtn && fileInput) {
    uploadBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
      if (!fileInput.files || !fileInput.files.length) return;
      addFiles(fileInput.files);
      fileInput.value = '';
      if (input) input.focus();
    });
  }
  // 本地拖拽上传：把文件从电脑拖到聊天区（消息区或输入区都行），松手就挂进附件栏；
  // 拖进来时整个聊天区盖一层“松开上传文件”提示。浏览器默认会直接打开拖进来的文件，
  // 所以 dragover/drop 都要 preventDefault，不然页面会被文件顶掉。
  var dragDepth = 0;
  function dragHasFiles(ev) {
    if (!ev.dataTransfer) return false;
    var types = ev.dataTransfer.types;
    if (!types) return false;
    for (var i = 0; i < types.length; i++) {
      if (types[i] === 'Files') return true;
    }
    return false;
  }
  function showDropOverlay(show) {
    if (!dropOverlay) return;
    dropOverlay.style.display = show ? 'flex' : 'none';
  }
  document.addEventListener('dragenter', function (ev) {
    if (!dragHasFiles(ev)) return;
    ev.preventDefault();
    dragDepth++;
    showDropOverlay(true);
  });
  document.addEventListener('dragover', function (ev) {
    if (!dragHasFiles(ev)) return;
    ev.preventDefault();
    if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'copy';
  });
  document.addEventListener('dragleave', function (ev) {
    if (!dragHasFiles(ev)) return;
    // relatedTarget 为空说明拖出窗口了，直接收掉提示，免得计数卡住一直盖着。
    if (!ev.relatedTarget) { dragDepth = 0; showDropOverlay(false); return; }
    dragDepth = Math.max(0, dragDepth - 1);
    if (dragDepth === 0) showDropOverlay(false);
  });
  document.addEventListener('drop', function (ev) {
    if (!dragHasFiles(ev)) return;
    ev.preventDefault();
    dragDepth = 0;
    showDropOverlay(false);
    if (ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files.length) {
      addFiles(ev.dataTransfer.files);
      if (input) input.focus();
    }
  });
  // 粘贴图片：从别处复制的图片直接粘贴进输入框，也挂进附件栏（和 Muse 一样）。
  if (input) {
    input.addEventListener('paste', function (ev) {
      var cd = ev.clipboardData;
      if (!cd || !cd.files || !cd.files.length) return;
      ev.preventDefault();
      addFiles(cd.files);
    });
  }
  function sendText(text, done) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'send.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status === 200) {
        scrollAfterSend = true;
        poll();
        if (done) done(true);
      } else if (done) done(false);
    };
    var body = 'content=' + encodeURIComponent(text) + '&csrf_token=' + encodeURIComponent(csrf) + '&room_id=' + currentRoom;
    if (replyToId > 0) body += '&reply_to_id=' + replyToId;
    xhr.send(body);
  }

  // 左边栏是会话列表（像 Muse 聊天界面那样）：最上面是主群，下面是各旁聊。
  // 点一个会话就切到那个对话（不刷新整页）；手机上默认收起，点 ☰ 再展开；
  // 展开后边栏盖住左上角的 ☰，所以栏里有 ×、外面有遮罩，点哪边都能关。
  var sideToggle = document.getElementById('side-toggle');
  var sidebar = document.getElementById('sidebar');
  var sideClose = document.getElementById('side-close');
  var sideBackdrop = document.getElementById('side-backdrop');
  function closeSidebar() { if (sidebar) sidebar.classList.remove('open'); }
  // 已归档区：点“已归档”展开/收起里面的旁聊；区里有几间，标题就显示几间。
  // 一间都没归档时整个区是藏着的，归档第一间时再露出来。
  var archivedSec = document.getElementById('archived-sec');
  var archivedList = document.getElementById('archived-list');
  var archivedToggle = document.getElementById('archived-toggle');
  function updateArchivedCount() {
    var n = archivedList ? archivedList.querySelectorAll('.room-row').length : 0;
    var c = document.getElementById('archived-count');
    if (c) c.textContent = n ? '（' + n + '）' : '';
    if (archivedSec) archivedSec.style.display = n ? '' : 'none';
  }
  if (archivedToggle && archivedList) {
    archivedToggle.addEventListener('click', function () {
      var open = archivedList.style.display !== 'none';
      archivedList.style.display = open ? 'none' : 'block';
      archivedToggle.setAttribute('aria-expanded', open ? 'false' : 'true');
      archivedToggle.classList.toggle('open', !open);
    });
  }
  if (sideToggle && sidebar) {
    sideToggle.addEventListener('click', function () { sidebar.classList.toggle('open'); });
  }
  if (sideClose) sideClose.addEventListener('click', closeSidebar);
  if (sideBackdrop) sideBackdrop.addEventListener('click', closeSidebar);
  document.addEventListener('click', function (ev) {
    var el = ev.target;
    while (el && el !== document) {
      if (el.classList && el.classList.contains('room-item')) {
        var rid = parseInt(el.getAttribute('data-room-id') || '0', 10);
        var rname = el.getAttribute('data-room-name') || '';
        if (rid > 0) loadRoom(rid, rname);
        return;
      }
      el = el.parentNode;
    }
  });

  // 新建旁聊：点“＋ 新建旁聊”，输个名字（比如“做视频”“生图”“代码”），
  // 建好后直接切进这个新旁聊。名字 1–20 字，空名字或取消不建。
  // 新建的房间也要带上 ⋯ 管理菜单（管理员才有），不然刚建的房间改不了名、删不掉。
  function buildRoomRow(roomId, roomName) {
    var li = document.createElement('li');
    li.className = 'room-row';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'side-item room-item';
    btn.setAttribute('data-room-id', String(roomId));
    btn.setAttribute('data-room-name', roomName);
    var av = document.createElement('span');
    av.className = 'side-avatar';
    av.textContent = roomName.charAt(0);
    var tx = document.createElement('span');
    tx.className = 'side-text';
    var nm = document.createElement('span');
    nm.className = 'side-name';
    nm.textContent = roomName;
    tx.appendChild(nm);
    btn.appendChild(av);
    btn.appendChild(tx);
    li.appendChild(btn);
    // ⋯ 菜单：归档人人能点（只影响自己的边栏），改名/删除只有管理员。
    // 新建的房间一定不是主群（id=1），所以归档项一定有。
    var menuBtn = document.createElement('button');
    menuBtn.type = 'button';
    menuBtn.className = 'room-menu-btn';
    menuBtn.setAttribute('data-room-id', String(roomId));
    menuBtn.setAttribute('aria-label', '管理“' + roomName + '”');
    menuBtn.textContent = '⋯';
    li.appendChild(menuBtn);
    var menu = document.createElement('div');
    menu.className = 'room-menu';
    menu.style.display = 'none';
    var arc = document.createElement('button');
    arc.type = 'button';
    arc.setAttribute('data-act', 'archive');
    arc.setAttribute('data-room-id', String(roomId));
    arc.setAttribute('data-room-name', roomName);
    arc.textContent = '📥 归档';
    menu.appendChild(arc);
    if (isAdmin) {
      var rn = document.createElement('button');
      rn.type = 'button';
      rn.setAttribute('data-act', 'rename');
      rn.setAttribute('data-room-id', String(roomId));
      rn.setAttribute('data-room-name', roomName);
      rn.textContent = '✎ 改名';
      menu.appendChild(rn);
      if (roomId !== 1) {
        var del = document.createElement('button');
        del.type = 'button';
        del.setAttribute('data-act', 'delete');
        del.setAttribute('data-room-id', String(roomId));
        del.setAttribute('data-room-name', roomName);
        del.textContent = '🗑 删除';
        menu.appendChild(del);
      }
    }
    li.appendChild(menu);
    return li;
  }
  var newRoomBtn = document.getElementById('new-room-btn');
  if (newRoomBtn) {
    newRoomBtn.addEventListener('click', function () {
      var name = window.prompt('新建旁聊的名字（比如“做视频”“生图”“代码”）：', '');
      if (name === null) return;
      name = name.replace(/^\s+|\s+$/g, '');
      if (!name) { alert('名字为空，没建。'); return; }
      if (name.length > 20) { alert('名字太长，最多 20 字。'); return; }
      newRoomBtn.disabled = true;
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'rooms.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        newRoomBtn.disabled = false;
        try {
          var res = JSON.parse(xhr.responseText);
          if (xhr.status === 200 && res && res.ok && res.id) {
            // 边栏里加一条，再切进去。
            var ul = document.querySelector('.room-list');
            if (ul) ul.appendChild(buildRoomRow(res.id, res.name));
            loadRoom(res.id, res.name);
          } else {
            alert('新建旁聊失败：' + ((res && res.error) || '请稍后再试'));
          }
        } catch (e) { alert('新建旁聊失败，请稍后再试'); }
      };
      xhr.send('name=' + encodeURIComponent(name) + '&csrf_token=' + encodeURIComponent(csrf));
    });
  }

  // 旁聊管理（⋯ 菜单，只有管理员能看到）：点 ⋯ 展开/收起这间的小菜单，
  // 点页面别处收起；主群（id=1）只有改名、没有删除。
  function closeRoomMenus(except) {
    var menus = document.querySelectorAll('.room-menu');
    Array.prototype.forEach.call(menus, function (m) {
      if (m !== except) m.style.display = 'none';
    });
  }
  document.addEventListener('click', function (ev) {
    var el = ev.target;
    // 点 ⋯ 按钮：开关这间的小菜单
    if (el.classList && el.classList.contains('room-menu-btn')) {
      var row = el.parentNode;
      var menu = row ? row.querySelector('.room-menu') : null;
      if (menu) {
        var wasOpen = menu.style.display !== 'none';
        closeRoomMenus();
        menu.style.display = wasOpen ? 'none' : 'block';
      }
      ev.stopPropagation();
      return;
    }
    // 点菜单里的改名 / 删除 / 归档 / 取消归档
    if (el.getAttribute && (el.getAttribute('data-act') === 'rename' || el.getAttribute('data-act') === 'delete' || el.getAttribute('data-act') === 'archive' || el.getAttribute('data-act') === 'unarchive')) {
      var act = el.getAttribute('data-act');
      var rid = parseInt(el.getAttribute('data-room-id') || '0', 10);
      var rname = el.getAttribute('data-room-name') || '';
      closeRoomMenus();
      if (!rid) return;
      if (act === 'archive' || act === 'unarchive') {
        // 归档/取消归档不用确认：可逆，只影响自己的边栏。
        roomArchive(act, rid);
      } else if (act === 'rename') {
        var newName = window.prompt('给“' + rname + '”改个名字：', rname);
        if (newName === null) return;
        newName = newName.replace(/^\s+|\s+$/g, '');
        if (!newName) { alert('名字为空，没改。'); return; }
        if (newName.length > 20) { alert('名字太长，最多 20 字。'); return; }
        if (newName === rname) return;
        roomManage('rename', rid, newName);
      } else {
        // 删除：再确认一次，删掉就连里面的全部消息一起没了，不可恢复。
        if (!window.confirm('确定删除旁聊“' + rname + '”吗？\n里面的全部消息都会一起删掉，删完不可恢复。')) return;
        roomManage('delete', rid, '');
      }
      ev.stopPropagation();
      return;
    }
    // 点别处：收起所有小菜单
    closeRoomMenus();
  });
  // 改名 / 删除调 rooms.php；改名成功更新边栏和标题，删除成功把那间从边栏去掉，
  // 正在看的那间被删了就切回主群（id=1）。
  function roomManage(action, roomId, name) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'rooms.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      try {
        var res = JSON.parse(xhr.responseText);
        if (xhr.status === 200 && res && res.ok) {
          if (action === 'rename') {
            var item = document.querySelector('.room-item[data-room-id="' + roomId + '"]');
            if (item) {
              item.setAttribute('data-room-name', name);
              var nmEl = item.querySelector('.side-name');
              if (nmEl) nmEl.textContent = name;
              var avEl = item.querySelector('.side-avatar');
              if (avEl) avEl.textContent = name.charAt(0);
            }
            // 菜单里的改名/删除按钮也要同步新名字，不然下次点还是旧名字。
            var acts = document.querySelectorAll('[data-act][data-room-id="' + roomId + '"]');
            Array.prototype.forEach.call(acts, function (b) {
              b.setAttribute('data-room-name', name);
            });
            if (roomId === currentRoom) {
              currentRoomName = name;
              if (roomTitle) roomTitle.textContent = name;
              if (input) input.placeholder = '发消息到“' + name + '”';
            }
          } else {
            // 删除：边栏去掉这间；正在看它就切回主群。
            var row = null;
            var delItem = document.querySelector('.room-item[data-room-id="' + roomId + '"]');
            if (delItem) row = delItem.parentNode;
            if (row && row.parentNode) row.parentNode.removeChild(row);
            if (roomId === currentRoom) {
              var mainItem = document.querySelector('.room-item[data-room-id="1"]');
              var mainName = mainItem ? (mainItem.getAttribute('data-room-name') || '群聊') : '群聊';
              loadRoom(1, mainName);
            }
          }
        } else {
          alert((action === 'rename' ? '改名失败：' : '删除失败：') + ((res && res.error) || '请稍后再试'));
        }
      } catch (e) { alert((action === 'rename' ? '改名失败，请稍后再试' : '删除失败，请稍后再试')); }
    };
    var body = 'action=' + encodeURIComponent(action) + '&room_id=' + roomId + '&csrf_token=' + encodeURIComponent(csrf);
    if (action === 'rename') body += '&name=' + encodeURIComponent(name);
    xhr.send(body);
  }

  // 归档 / 取消归档：只影响自己的边栏（房间和消息都在，别人的边栏不动）。
  // 成功后把那行搬到另一边列表里（按房间 id 排），再拉一次轮询刷新蓝点和标题未读数。
  function roomArchive(action, roomId) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'rooms.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      try {
        var res = JSON.parse(xhr.responseText);
        if (xhr.status === 200 && res && res.ok) {
          moveRoomRow(roomId, action === 'archive');
          poll();
        } else {
          alert((action === 'archive' ? '归档失败：' : '取消归档失败：') + ((res && res.error) || '请稍后再试'));
        }
      } catch (e) { alert(action === 'archive' ? '归档失败，请稍后再试' : '取消归档失败，请稍后再试'); }
    };
    xhr.send('action=' + encodeURIComponent(action) + '&room_id=' + roomId + '&csrf_token=' + encodeURIComponent(csrf));
  }
  function moveRoomRow(roomId, toArchived) {
    var item = document.querySelector('.room-item[data-room-id="' + roomId + '"]');
    if (!item) return;
    var row = item.parentNode;
    if (!row || !row.parentNode) return;
    row.parentNode.removeChild(row);
    // 菜单里的 归档/取消归档 换一下；改名/删除不动。
    var menuBtn = row.querySelector('[data-act="archive"], [data-act="unarchive"]');
    if (menuBtn) {
      if (toArchived) {
        menuBtn.setAttribute('data-act', 'unarchive');
        menuBtn.textContent = '📤 取消归档';
      } else {
        menuBtn.setAttribute('data-act', 'archive');
        menuBtn.textContent = '📥 归档';
      }
    }
    var target = toArchived ? archivedList : document.querySelector('.room-list');
    if (!target) return;
    insertRowSorted(target, row);
    updateArchivedCount();
    // 归档区之前没内容时是藏着的，归档第一间就露出来。
    if (toArchived && archivedSec) archivedSec.style.display = '';
  }
  function insertRowSorted(ul, row) {
    var item = row.querySelector('.room-item');
    var id = item ? parseInt(item.getAttribute('data-room-id') || '0', 10) : 0;
    var rows = ul.querySelectorAll('.room-row');
    for (var i = 0; i < rows.length; i++) {
      var it = rows[i].querySelector('.room-item');
      var oid = it ? parseInt(it.getAttribute('data-room-id') || '0', 10) : 0;
      if (oid > id) { ul.insertBefore(row, rows[i]); return; }
    }
    ul.appendChild(row);
  }

  if (input) {
    input.addEventListener('input', function () { autoGrow(); updateMention(); });
    input.addEventListener('keydown', function (ev) {
      if (mentionBox && mentionBox.style.display === 'block' && mentionList.length) {
        if (ev.key === 'ArrowDown') { ev.preventDefault(); moveMention(1); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); moveMention(-1); }
        else if (ev.key === 'Enter' || ev.key === 'Tab') { ev.preventDefault(); pickMention(mentionList[mentionSel < 0 ? 0 : mentionSel]); }
        else if (ev.key === 'Escape') { ev.preventDefault(); closeMention(); }
        return;
      }
      // Enter 直接发，Shift+Enter 换行（不发送）。
      if (ev.key === 'Enter' && !ev.shiftKey) {
        ev.preventDefault();
        if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
      }
    });
    input.addEventListener('blur', function () { setTimeout(closeMention, 150); });
    autoGrow();
  }

  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var text = input.value.trim();
      var ready = [];
      var failed = 0;
      pendingAttach.forEach(function (a) {
        if (a.state === 'ready') ready.push(a);
        else if (a.state === 'error') failed++;
      });
      if (!text && ready.length === 0) return;
      if (anyUploading()) return; // 还在传，等传完（发送按钮这时也是禁用的）
      if (failed > 0 && !confirm('有 ' + failed + ' 个附件上传失败，不会随这条消息发出，确定发送吗？')) return;
      // 文字 + 附件直链各一行，一起发成一条消息；没文字、只有附件也照发。
      // 直链里的中文文件名和空格先编码，存进消息后链接才不会在空格/中文处断掉；渲染时再解回文件名显示。
      var parts = [];
      if (text) parts.push(text);
      ready.forEach(function (a) { parts.push(encodeURI(a.url)); });
      sendText(parts.join('\n'), function (ok) {
        if (ok) {
          input.value = '';
          clearAttach();
          clearReply();
          autoGrow();
          input.focus();
        }
      });
    });
  }
})();
