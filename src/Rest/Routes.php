<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Rest;

use IndianOpinion\ReadAloud\Plugin;
use IndianOpinion\ReadAloud\Review\Review;

/**
 * REST routes under read-aloud/v1 (PRD 5.5).
 *
 * Every write route checks the REST nonce and the capability on the
 * specific post, and refuses with a stable error code.
 */
final class Routes
{
    public const NS = 'read-aloud/v1';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        $id = ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]];
        register_rest_route(self::NS, '/posts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getPost'],
            'permission_callback' => '__return_true',
            'args' => $id,
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/generate', [
            'methods' => 'POST',
            'callback' => [$this, 'generate'],
            'permission_callback' => [$this, 'canWritePost'],
            'args' => $id + ['force' => ['type' => 'boolean', 'default' => false]],
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/approve', [
            'methods' => 'POST',
            'callback' => [$this, 'approve'],
            'permission_callback' => [$this, 'canWritePost'],
            'args' => $id,
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/reject', [
            'methods' => 'POST',
            'callback' => [$this, 'reject'],
            'permission_callback' => [$this, 'canWritePost'],
            'args' => $id,
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/audio', [
            'methods' => 'DELETE',
            'callback' => [$this, 'remove'],
            'permission_callback' => [$this, 'canWritePost'],
            'args' => $id,
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/setting', [
            'methods' => 'POST',
            'callback' => [$this, 'setting'],
            'permission_callback' => [$this, 'canWritePost'],
            'args' => $id + ['mode' => ['type' => 'string', 'required' => true, 'enum' => ['inherit', 'on', 'off']]],
        ]);
        register_rest_route(self::NS, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'status'],
            'permission_callback' => [$this, 'canManage'],
        ]);
    }

    private function postId(\WP_REST_Request $request): int
    {
        return (int) $request->get_param('id');
    }

    private function nonceOk(\WP_REST_Request $request): bool
    {
        $nonce = $request->get_header('x_wp_nonce');
        if ($nonce === null || $nonce === '') {
            $nonce = $request->get_param('_wpnonce');
        }

        return is_string($nonce) && $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest') !== false;
    }

    public function canWritePost(\WP_REST_Request $request): bool|\WP_Error
    {
        $postId = $this->postId($request);
        if (!get_post($postId) instanceof \WP_Post) {
            return new \WP_Error('read_aloud_not_found', 'No such post.', ['status' => 404]);
        }
        if (!$this->nonceOk($request)) {
            return new \WP_Error('read_aloud_bad_nonce', 'The request needs a valid REST nonce.', ['status' => 403]);
        }
        if (!Review::canReview($postId)) {
            return new \WP_Error('read_aloud_forbidden', 'You cannot change audio for this post.', ['status' => 403]);
        }

        return true;
    }

    public function canManage(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!Review::canManage()) {
            return new \WP_Error('read_aloud_forbidden', 'You cannot see the read-aloud status.', ['status' => 403]);
        }

        return true;
    }

    public function getPost(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $postId = $this->postId($request);
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('read_aloud_not_found', 'No such post.', ['status' => 404]);
        }
        if (Review::canReview($postId)) {
            return new \WP_REST_Response($this->plugin->editorState($post));
        }
        $record = $this->plugin->player()->playable($post);
        if ($record === null || $post->post_status !== 'publish' || $post->post_password !== '') {
            return new \WP_REST_Response(['ready' => false, 'url' => null, 'mime' => null, 'duration' => null]);
        }

        return new \WP_REST_Response([
            'ready' => true,
            'url' => $record->fileUrl,
            'mime' => $record->mime,
            'duration' => $record->duration,
        ]);
    }

    public function generate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $postId = $this->postId($request);
        $force = (bool) $request->get_param('force');
        $result = $this->plugin->queue()->trigger($postId, 'generate', ['force' => $force, 'fresh' => $force]);
        if ($result === 'ineligible' || str_starts_with($result, 'skipped:')) {
            $post = get_post($postId);
            $reason = $post instanceof \WP_Post ? $this->plugin->planner()->plan($post)->reason : 'missing';
            return new \WP_Error('read_aloud_not_eligible', 'This post is not eligible for audio: ' . $reason . '.', ['status' => 409, 'reason' => $reason]);
        }
        $response = ['result' => $result, 'enabled' => $this->plugin->guard()->enabled(), 'held' => $this->plugin->guard()->isHeld()];
        $post = get_post($postId);
        if ($post instanceof \WP_Post) {
            $response['state'] = $this->plugin->editorState($post);
        }

        return new \WP_REST_Response($response);
    }

    public function approve(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $postId = $this->postId($request);
        $result = $this->plugin->review()->approve($postId, get_current_user_id());

        return $result instanceof \WP_Error ? $result : $this->state($postId);
    }

    public function reject(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $postId = $this->postId($request);
        $result = $this->plugin->review()->reject($postId, get_current_user_id());

        return $result instanceof \WP_Error ? $result : $this->state($postId);
    }

    public function remove(\WP_REST_Request $request): \WP_REST_Response
    {
        $postId = $this->postId($request);
        $this->plugin->review()->remove($postId, get_current_user_id());

        return $this->state($postId);
    }

    public function setting(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $postId = $this->postId($request);
        $mode = (string) $request->get_param('mode');
        if (!in_array($mode, ['inherit', 'on', 'off'], true)) {
            return new \WP_Error('read_aloud_invalid_mode', 'Mode must be inherit, on or off.', ['status' => 400]);
        }
        $this->plugin->records()->setMode($postId, $mode);
        $this->plugin->queue()->trigger($postId, 'setting');

        return $this->state($postId);
    }

    public function status(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response($this->plugin->statusData());
    }

    private function state(int $postId): \WP_REST_Response
    {
        $post = get_post($postId);

        return new \WP_REST_Response($post instanceof \WP_Post ? $this->plugin->editorState($post) : []);
    }
}
