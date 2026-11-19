<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Data\Status;

/**
 * PRD 12.1, cases 1 to 12 and 34, 35.
 */
final class EligibilityTest extends TestCase
{
    /** Test 1 (case 1). */
    public function testPublishedPostInOptedInCategoryIsEligibleAndPageIsNot(): void
    {
        $post = $this->post();
        $this->assertTrue(read_aloud_is_eligible($post->ID));
        $page = $this->post(['post_type' => 'page']);
        $this->assertFalse(read_aloud_is_eligible($page->ID));
    }

    /** Test 2 (case 2). */
    public function testOffWinsOverCategory(): void
    {
        $post = $this->post();
        $this->plugin->records()->setMode($post->ID, Records::MODE_OFF);
        $this->assertFalse(read_aloud_is_eligible($post->ID));
    }

    /** Test 3 (case 3). */
    public function testOnWithoutOptedInCategory(): void
    {
        $post = $this->post([], [self::SPORT]);
        $this->assertFalse(read_aloud_is_eligible($post->ID));
        $this->plugin->records()->setMode($post->ID, Records::MODE_ON);
        $this->assertTrue(read_aloud_is_eligible($post->ID));
    }

    /** Test 4 (case 4): defaults queue nothing. */
    public function testFreshInstallQueuesNothing(): void
    {
        $this->plugin->settings()->replace(['provider' => 'fake'] + \IndianOpinion\ReadAloud\Settings\Settings::baseDefaults());
        $this->plugin->activate();
        $post = $this->post();
        $this->assertSame('ineligible', $this->trigger($post->ID));
        $this->assertSame([], \WpStub::cronFor('read_aloud_job'));
        $this->assertSame([], $this->provider->requests);
        $this->assertSame([], \WpStub::$http);
    }

    /** Test 5 (case 5). */
    public function testChildCategoriesOnlyWithSetting(): void
    {
        $post = $this->post([], [self::LOCAL]);
        $this->assertFalse(read_aloud_is_eligible($post->ID));
        $this->settings(['include_children' => true]);
        $this->assertTrue(read_aloud_is_eligible($post->ID));
    }

    /** Test 6 (case 6). */
    public function testAnyOptedInCategoryIsEnough(): void
    {
        $post = $this->post([], [self::SPORT, self::NEWS]);
        $this->assertTrue(read_aloud_is_eligible($post->ID));
    }

    /** Test 7 (case 7). */
    public function testOptingInQueuesNothingForExistingPostsUntilUpdated(): void
    {
        $this->settings(['categories' => []]);
        $post = $this->post([], [self::SPORT]);
        $this->settings(['categories' => [self::SPORT]]);
        $this->assertSame([], \WpStub::cronFor('read_aloud_job'));
        $this->assertSame(Status::NONE, $this->record($post->ID)->status);
        $this->assertSame('queued', $this->trigger($post->ID, 'update'));
    }

    /** Test 8 (case 8): unpublished text never reaches the provider. */
    public function testDraftsAndPrivateNeverReachProvider(): void
    {
        foreach (['draft', 'pending', 'private', 'future'] as $status) {
            $post = $this->post(['post_status' => $status]);
            $this->plugin->records()->setMode($post->ID, Records::MODE_ON);
            $this->assertSame('ineligible', $this->trigger($post->ID, 'generate', ['force' => true]));
            $this->assertSame('nothing', $this->run_($post->ID));
        }
        $protected = $this->post(['post_password' => 'secret']);
        $this->plugin->records()->setMode($protected->ID, Records::MODE_ON);
        $this->assertSame('ineligible', $this->trigger($protected->ID, 'generate', ['force' => true]));
        $this->assertSame([], $this->provider->requests);
    }

    /** Test 9 (cases 9, 10). */
    public function testLengthLimits(): void
    {
        $short = $this->post(['post_title' => 'Hi', 'post_content' => '<p>Short.</p>']);
        $this->assertSame('skipped:too_short', $this->trigger($short->ID));
        $this->assertSame('too_short', $this->record($short->ID)->reason);

        $this->settings(['max_chars' => 500]);
        $long = $this->post();
        $this->assertSame('skipped:too_long', $this->trigger($long->ID));
        $this->assertSame([], $this->provider->requests);
        add_filter('read_aloud_max_chars', fn (int $max, \WP_Post $post): int => $post->ID === $long->ID ? 5000 : $max, 10, 2);
        $this->assertSame('queued', $this->trigger($long->ID));
    }

    /** Test 10 (cases 11, 34, 35). */
    public function testVoiceLookupByLocale(): void
    {
        $post = $this->post();
        \WpStub::$locale = 'fr_FR';
        $this->assertSame('skipped:no_voice', $this->trigger($post->ID));

        $this->settings(['voice_map' => [['locale' => 'pt', 'voice' => 'pt-voice', 'model' => '', 'speed' => 1.0, 'format' => 'mp3']]]);
        \WpStub::$locale = 'pt_BR';
        $this->assertSame('pt-voice', $this->plugin->planner()->voiceFor('pt-BR')['voice'] ?? null);
        $this->assertSame('queued', $this->trigger($post->ID));

        \WpStub::$locale = 'en_GB';
        add_filter('read_aloud_post_locale', fn (string $locale): string => 'pt-PT');
        $this->assertSame('pt-PT', $this->plugin->planner()->locale($post));
        $this->assertTrue(read_aloud_is_eligible($post->ID));
    }

    /** Test 11 (case 12). */
    public function testIneligibleHidesPlayerAndReturnsWithoutCall(): void
    {
        $post = $this->approvedPost();
        $calls = count($this->provider->requests);
        $this->assertNotSame('', read_aloud_player($post->ID));
        $this->plugin->records()->setMode($post->ID, Records::MODE_OFF);
        $this->trigger($post->ID, 'setting');
        $this->assertSame('', read_aloud_player($post->ID));
        $this->plugin->records()->setMode($post->ID, Records::MODE_INHERIT);
        $this->assertSame('unchanged', $this->trigger($post->ID, 'setting'));
        $this->assertNotSame('', read_aloud_player($post->ID));
        $this->assertSame($calls, count($this->provider->requests));
    }
}
