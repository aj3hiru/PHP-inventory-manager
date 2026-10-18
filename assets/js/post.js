document.addEventListener('DOMContentLoaded', () => {
  // === 1. NON-BLOCKING: Load localStorage data AFTER paint ===
  requestAnimationFrame(() => {
    requestIdleCallback(loadCommentData, { timeout: 1000 });
  });

  const f = document.getElementById('blog-comment-form');
  if (!f) return;

  const id = f.dataset.postId;
  const sb = document.getElementById('blog-comment-submit-btn');
  const ls = document.getElementById('blog-comment-loading-spinner');
  const ab = document.getElementById('blog-comment-alert-container');
  const cb = document.getElementById('blog-comments-container');

  // === 2. FORM SUBMIT ===
  f.addEventListener('submit', async e => {
    e.preventDefault();
    sb.disabled = true;
    ls.classList.add('active');

    const fd = new FormData(f);
        try {
      const r = await fetch(`/api/a1b2c3d4e5.php?id=${id}`, { method: 'POST', body: fd });
      const d = await r.json();
      if (d.success) {
        CDAlert.show(d.message, 'success', 'top');
        saveCommentData(fd);
        addComment(d.comment, d.parent_name);
        document.getElementById('blog-comment-content').value = '';
        cancelReply();
        setTimeout(() => scrollTo(d.comment.id), 100);
      } else {
        CDAlert.show(d.message, 'error', 'top');
      }
    } catch {
      CDAlert.show('error', 'top', 'An error occurred. Please try again.');
    } finally {
      sb.disabled = false;
      ls.classList.remove('active');
    }
  });

  // === 3. REPLY & CANCEL ===
  document.addEventListener('click', e => {
    const t = e.target;
    if (t.classList.contains('blog-comment-reply-btn')) {
      replyTo(t.dataset.id, t.dataset.name);
    }
    if (t.classList.contains('blog-comment-cancel-reply')) {
      cancelReply();
    }
  });

  function replyTo(pid, name) {
    document.getElementById('blog-comment-parent-id').value = pid;
    document.getElementById('blog-comment-reply-to-name').textContent = name;
    document.getElementById('blog-comment-replying-to').classList.add('active');
    document.getElementById('blog-comment-content').focus();
    requestAnimationFrame(() => f.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
  }

  function cancelReply() {
    document.getElementById('blog-comment-parent-id').value = '';
    document.getElementById('blog-comment-replying-to').classList.remove('active');
  }

  // === 4. ALERTS ===
/*  function showAlert(type, msg) {
    ab.innerHTML = `<div class="blog-comment-alert blog-comment-alert-${type} show">${escape(msg)}</div>`;
    setTimeout(clearAlert, 5000);
  }
  function clearAlert() { ab.innerHTML = ''; }
*/

  // === 5. ADD COMMENT ===
  function addComment(c, pn) {
    const html = commentHTML(c, pn);
    const frag = document.createRange().createContextualFragment(html);
    if (c.parent_id) {
      const parent = document.querySelector(`[data-comment-id="${c.parent_id}"]`);
      if (parent) {
        let replies = parent.querySelector('.blog-comment-replies-container');
        if (!replies) {
          replies = document.createElement('div');
          replies.className = 'blog-comment-replies-container';
          parent.appendChild(replies);
        }
        replies.appendChild(frag);
      }
    } else {
      if (cb.querySelector('p')) cb.innerHTML = '';
      cb.appendChild(frag);
    }
  }

  function commentHTML(c, pn) {
    const date = new Date(c.date).toLocaleDateString('en-US', {
      year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
    });
    const cls = c.parent_id ? 'blog-comment-item blog-comment-reply' : 'blog-comment-item';
    const replyTo = pn ? `<div style="color:#6c757d;font-size:0.85rem;margin-bottom:5px;">Replying to <strong>${escape(pn)}</strong></div>` : '';
    return `<div class="${cls}" data-comment-id="${c.id}">
      <div class="blog-comment-header">
        <span class="blog-comment-author">${escape(c.name)}</span>
        <span class="blog-comment-date">${date}</span>
      </div>
      ${replyTo}
      <div class="blog-comment-content">${escape(c.content)}</div>
      <div class="blog-comment-actions">
        <button class="blog-comment-reply-btn" data-id="${c.id}" data-name="${escape(c.name)}">Reply</button>
      </div>
    </div>`;
  }

  function escape(t) {
    return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // === 6. SCROLL TO COMMENT ===
  function scrollTo(cid) {
    const el = document.querySelector(`[data-comment-id="${cid}"]`);
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      el.style.backgroundColor = '#fffacd';
      setTimeout(() => el.style.backgroundColor = '', 2000);
    }
  }

  // === 7. LOAD MORE COMMENTS ===
  const loadBtn = document.getElementById('load-more-comments');
  if (loadBtn) {
    const limit = 5;
    loadBtn.onclick = async () => {
      const offset = +loadBtn.dataset.offset;
      
      loadBtn.disabled = true;
  loadBtn.textContent = 'Loading...';
  
      const res = await fetch(`/api/1a2b3c4d5e.php?pId=${id}&offset=${offset}`);
      const d = await res.json();
      if (d.html?.trim()) {
        cb.appendChild(document.createRange().createContextualFragment(d.html));
        loadBtn.dataset.offset = offset + limit;
        
        loadBtn.disabled = false;
    loadBtn.innerHTML = 'Show more &#65291;';
      } else {
        loadBtn.style.display = 'none';
      }
    };
  }

  // === 8. TOKEN FETCH (IDLE) ===
  (requestIdleCallback || (f => setTimeout(f)))(() => {
    fetch('/api/f0a9c7b3d1.php')
      .then(r => r.json())
      .then(d => {
        const e = document.getElementById('cTkn-inpt');
        if (e) e.value = d.token;
      })
      .catch(() => {});
  });

  // === 9. SHARE BUTTON ===
  const shareBtn = document.getElementById('shareBtn');
  if (shareBtn) {
    shareBtn.onclick = () => {
      if (navigator.share) {
        navigator.share({ title: document.title, url: location.href });
      } else {
        alert('Sharing not supported on this browser.');
      }
    };
  }

  // === 10. POST VIEW TRACKING (FIRE & FORGET) ===
 // id && fetch('/api/0f9e8d7c6b.php', {
//    method: 'POST',
//    body: new URLSearchParams({ id }),
//    keepalive: true
//  }).catch(() => {}); 

 if (!sessionStorage.getItem(id)) {
   navigator.sendBeacon('/api/0f9e8d7c6n.php', new URLSearchParams({ id, ref: document.referrer || '' }));
   sessionStorage.setItem(id, '1');
 }
  
  // === 11. TOC COLLAPSE SHOW FUNCTIONALITY ==========
  const toc = document.querySelector('.toc');
  if (!toc) return;
  const h2 = toc.querySelector('h2');
  if (!h2) return;
  const button = document.createElement('button');
  button.textContent = '[Hide]';
  h2.appendChild(button);
  const ol = toc.querySelector(':scope > ol');
  ol.style.height = ol.scrollHeight + "px";
  button.addEventListener('click', () => {
    const isCollapsed = toc.classList.toggle('collapsed');
    ol.style.height = isCollapsed ? '0px' : ol.scrollHeight + 'px';
    button.textContent = isCollapsed ? '[Show]' : '[Hide]';
  });

});

// === 12: LOCALSTORAGE HANDLING ========
function loadCommentData() {
  const nameInput = document.getElementById('blog-comment-name');
  const emailInput = document.getElementById('blog-comment-email');
  const remember = document.getElementById('blog-comment-remember');

  if (!nameInput || !emailInput || !remember) return;

  // Read in <1ms, no layout thrashing
  const shouldRemember = localStorage.getItem('comment_remember') === 'true';
  if (!shouldRemember) return;

  const name = localStorage.getItem('comment_name');
  const email = localStorage.getItem('comment_email');

  if (name) nameInput.value = name;
  if (email) emailInput.value = email;
  remember.checked = true;
}

function saveCommentData(fd) {
  const remember = document.getElementById('blog-comment-remember');
  if (!remember) return;

  if (remember.checked) {
    const name = fd.get('name')?.trim();
    const email = fd.get('email')?.trim();
    if (name && email) {
      localStorage.setItem('comment_name', name);
      localStorage.setItem('comment_email', email);
      localStorage.setItem('comment_remember', 'true');
    }
  } else {
    localStorage.removeItem('comment_name');
    localStorage.removeItem('comment_email');
    localStorage.setItem('comment_remember', 'false');
  }
}


// === 13: FAQ FN  ========
document.querySelectorAll('.q').forEach(b => {
    b.onclick = () => {
        let a = b.nextElementSibling;
        let i = b.querySelector('.sp');
        let o = a.style.maxHeight;
        a.style.maxHeight = o ? null : a.scrollHeight + "px";
        i.style.transform = o ? "rotate(0deg)" : "rotate(180deg)";
    };
});