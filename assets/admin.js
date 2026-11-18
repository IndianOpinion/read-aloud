/* read-aloud: classic editor meta box buttons. Calls the REST routes with the REST nonce. */
(function () {
  'use strict';
  var data = window.readAloudAdmin;
  if (!data) {
    return;
  }
  var routes = {
    generate: { path: 'generate', method: 'POST', body: { force: true } },
    approve: { path: 'approve', method: 'POST' },
    reject: { path: 'reject', method: 'POST' },
    remove: { path: 'audio', method: 'DELETE', confirm: 'Delete the audio files and turn audio off for this post?' }
  };
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-read-aloud-action]');
    if (!button) {
      return;
    }
    var route = routes[button.getAttribute('data-read-aloud-action')];
    if (!route || (route.confirm && !window.confirm(route.confirm))) {
      return;
    }
    var box = button.closest('.inside') || document;
    var message = box.querySelector('.read-aloud-message');
    button.disabled = true;
    fetch(data.root + 'posts/' + data.postId + '/' + route.path, {
      method: route.method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
      body: route.body ? JSON.stringify(route.body) : undefined
    })
      .then(function (response) {
        return response.json().then(function (json) {
          return { ok: response.ok, json: json };
        });
      })
      .then(function (result) {
        if (message) {
          message.textContent = result.ok ? 'Done. Reload the page to see the new state.' : (result.json.message || 'The request failed.');
        }
      })
      .catch(function () {
        if (message) {
          message.textContent = 'The request failed.';
        }
      })
      .finally(function () {
        button.disabled = false;
      });
  });
})();
