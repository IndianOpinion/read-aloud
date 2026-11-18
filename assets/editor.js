/* read-aloud: block editor panel and the player block. No build step. */
(function (wp) {
  'use strict';
  if (!wp || !wp.element) {
    return;
  }
  var el = wp.element.createElement;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var __ = wp.i18n ? wp.i18n.__ : function (s) { return s; };
  var components = wp.components;

  if (wp.blocks && !wp.blocks.getBlockType('read-aloud/player')) {
    wp.blocks.registerBlockType('read-aloud/player', {
      apiVersion: 3,
      title: __('Read aloud player', 'read-aloud'),
      icon: 'controls-volumeon',
      category: 'media',
      supports: { html: false, multiple: false },
      edit: function () {
        return el('div', { className: 'read-aloud-placeholder' }, __('The audio player appears here once audio is approved.', 'read-aloud'));
      },
      save: function () {
        return null;
      }
    });
  }

  var data = window.readAloudEditor;
  var PanelComponent = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
  if (!data || !wp.plugins || !PanelComponent || !wp.apiFetch) {
    return;
  }
  var base = '/read-aloud/v1/posts/' + data.postId;

  function Panel() {
    var stateHook = useState(null);
    var state = stateHook[0];
    var setState = stateHook[1];
    var messageHook = useState('');
    var message = messageHook[0];
    var setMessage = messageHook[1];

    function load() {
      wp.apiFetch({ path: base }).then(setState).catch(function (error) {
        setMessage(error.message || 'Could not load the audio state.');
      });
    }
    useEffect(load, []);

    function call(path, method, body) {
      setMessage('');
      wp.apiFetch({ path: base + path, method: method, data: body })
        .then(function (result) {
          setState(result.state || result);
        })
        .catch(function (error) {
          setMessage(error.message || 'The request failed.');
        });
    }

    if (!state) {
      return el(PanelComponent, { name: 'read-aloud', title: __('Read aloud', 'read-aloud') }, el('p', null, message || __('Loading...', 'read-aloud')));
    }
    var children = [];
    children.push(el(components.SelectControl, {
      key: 'mode',
      label: __('Audio for this post', 'read-aloud'),
      value: state.mode,
      options: [
        { label: __('Inherit', 'read-aloud') + ' (' + state.inherit_resolves + ')', value: 'inherit' },
        { label: __('On', 'read-aloud'), value: 'on' },
        { label: __('Off', 'read-aloud'), value: 'off' }
      ],
      onChange: function (mode) { call('/setting', 'POST', { mode: mode }); }
    }));
    children.push(el('p', { key: 'status' }, __('Status:', 'read-aloud') + ' ' + state.status + (state.reason ? ' (' + state.reason + ')' : '')));
    if (state.estimated_chars) {
      children.push(el('p', { key: 'chars' }, __('About', 'read-aloud') + ' ' + state.estimated_chars + ' ' + __('characters.', 'read-aloud')));
    }
    if (state.next_attempt_at) {
      children.push(el('p', { key: 'next' }, __('Next attempt:', 'read-aloud') + ' ' + new Date(state.next_attempt_at * 1000).toLocaleString()));
    }
    if (state.last_error_code) {
      children.push(el('p', { key: 'error' }, __('Last error:', 'read-aloud') + ' ' + state.last_error_code + ' ' + state.last_error_message));
    }
    if (state.pending) {
      children.push(el('p', { key: 'pending-title' }, el('strong', null, __('Waiting for review', 'read-aloud'))));
      children.push(el('audio', { key: 'pending-audio', controls: true, preload: 'none', src: state.pending.url, style: { width: '100%' } }));
      children.push(el('p', { key: 'pending-meta' }, Math.round(state.pending.duration) + ' s, ' + state.pending.chars + ' ' + __('characters billed.', 'read-aloud')));
      children.push(el('details', { key: 'text' }, el('summary', null, __('Text that was read', 'read-aloud')), el('div', { style: { maxHeight: '12em', overflow: 'auto', whiteSpace: 'pre-wrap' } }, state.spoken_text)));
      children.push(el(components.Button, { key: 'approve', variant: 'primary', onClick: function () { call('/approve', 'POST'); } }, __('Approve', 'read-aloud')));
      children.push(el(components.Button, { key: 'reject', variant: 'secondary', onClick: function () { call('/reject', 'POST'); } }, __('Reject', 'read-aloud')));
    }
    if (state.live) {
      children.push(el('p', { key: 'live-title' }, __('Live audio', 'read-aloud') + (state.live.stale ? ' (' + __('older text', 'read-aloud') + ')' : '')));
      children.push(el('audio', { key: 'live-audio', controls: true, preload: 'none', src: state.live.url, style: { width: '100%' } }));
    }
    children.push(el('p', { key: 'actions' },
      el(components.Button, { variant: 'secondary', onClick: function () { call('/generate', 'POST', { force: true }); } }, __('Generate now', 'read-aloud')),
      ' ',
      el(components.Button, {
        variant: 'link',
        isDestructive: true,
        onClick: function () {
          if (window.confirm(__('Delete the audio files and turn audio off for this post?', 'read-aloud'))) {
            call('/audio', 'DELETE');
          }
        }
      }, __('Remove audio', 'read-aloud'))
    ));
    if (message) {
      children.push(el('p', { key: 'message', role: 'status' }, message));
    }
    return el(PanelComponent, { name: 'read-aloud', title: __('Read aloud', 'read-aloud') }, children);
  }

  wp.plugins.registerPlugin('read-aloud', { render: Panel });
})(window.wp);
