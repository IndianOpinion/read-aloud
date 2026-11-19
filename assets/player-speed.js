/* read-aloud: optional speed buttons. No requests, no tracking. */
(function () {
  'use strict';
  var speeds = [1, 1.25, 1.5, 2];
  function setup(figure) {
    var audio = figure.querySelector('audio');
    if (!audio || figure.querySelector('.read-aloud__speeds')) {
      return;
    }
    var group = document.createElement('div');
    group.className = 'read-aloud__speeds';
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', 'Playback speed');
    speeds.forEach(function (speed) {
      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = speed + 'x';
      button.setAttribute('aria-pressed', speed === 1 ? 'true' : 'false');
      button.addEventListener('click', function () {
        audio.playbackRate = speed;
        group.querySelectorAll('button').forEach(function (other) {
          other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
        });
      });
      group.appendChild(button);
    });
    audio.insertAdjacentElement('afterend', group);
  }
  function init() {
    document.querySelectorAll('figure[data-read-aloud]').forEach(setup);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
