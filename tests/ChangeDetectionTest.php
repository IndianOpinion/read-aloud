<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Maintenance;
use IndianOpinion\ReadAloud\Text\Hashes;

/**
 * PRD 12.2, cases 13 to 20.
 */
final class ChangeDetectionTest extends TestCase
{
    /** Test 12 (cases 13, 15). */
    public function testNonSpokenChangesQueueNothingAndCount(): void
    {
        $post = $this->approvedPost();
        $calls = count($this->provider->requests);
        $content = $post->post_content;

        \WpStub::$postTerms[$post->ID][] = self::SPORT;
        $this->assertSame('unchanged', $this->trigger($post->ID, 'terms'));
        update_post_meta($post->ID, '_thumbnail_id', 99);
        $this->assertSame('unchanged', $this->trigger($post->ID, 'update'));
        $post->post_name = 'new-slug';
        $this->assertSame('unchanged', $this->trigger($post->ID, 'update'));
        $this->edit($post, str_replace('<p>', "<p>  \n ", $content) . "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->");
        $this->assertSame('unchanged', $this->trigger($post->ID, 'update'));
        $this->edit($post, str_replace('<p>The', '<p><strong>The</strong>', $content));
        $this->assertSame('unchanged', $this->trigger($post->ID, 'update'));

        $this->assertSame(5, $this->today()['saves_skipped']);
        $this->assertSame($calls, count($this->provider->requests));
        $this->assertSame(Status::APPROVED, $this->record($post->ID)->status);
    }

    /** Test 13 (cases 14, 16). */
    public function testDebounceAndMaximumWait(): void
    {
        $post = $this->approvedPost();
        $start = $this->clock->now();
        $this->edit($post, str_replace('council', 'committee', $post->post_content));
        $this->assertSame('queued', $this->trigger($post->ID, 'update'));
        $events = \WpStub::cronFor('read_aloud_job');
        $this->assertCount(1, $events);
        $this->assertSame($start + 60 + 600, $events[0][0]);

        for ($i = 0; $i < 2; $i++) {
            $this->edit($post, str_replace('committee', 'committee ' . $i, $post->post_content));
            $this->trigger($post->ID, 'update');
        }
        $this->assertCount(1, \WpStub::cronFor('read_aloud_job'), 'three saves leave one job');

        // Continuous saves every 5 minutes still run by the maximum wait.
        $first = (int) $this->plugin->records()->get($post->ID)['job']['first_at'];
        for ($i = 0; $i < 20; $i++) {
            $this->clock->advance(300);
            $post->post_content .= ' x';
            $this->trigger($post->ID, 'update');
        }
        $job = $this->plugin->records()->get($post->ID)['job'];
        $this->assertSame($first + 3600, $job['run_at']);
        $this->assertSame($first + 3600, \WpStub::cronFor('read_aloud_job')[0][0]);
    }

    /** Test 14 (case 13). */
    public function testRevisionsAndAutosavesNeverQueue(): void
    {
        $revision = $this->post(['post_type' => 'revision', 'post_parent' => 1]);
        $this->assertSame('ineligible', $this->trigger($revision->ID, 'update'));
        $autosave = $this->post();
        \WpStub::$autosaves[] = $autosave->ID;
        $this->assertSame('ineligible', $this->trigger($autosave->ID, 'update'));
        $this->plugin->onAfterInsert($autosave->ID, $autosave, true, $autosave);
        $this->assertSame([], $this->plugin->flushDeferred());
        $this->assertSame([], \WpStub::cronFor('read_aloud_job'));
    }

    /** Test 15 (case 17). */
    public function testVoiceChangeMarksOutdatedAndQueuesNothing(): void
    {
        $post = $this->approvedPost();
        $calls = count($this->provider->requests);
        $page = new \IndianOpinion\ReadAloud\Admin\SettingsPage($this->plugin);
        $errors = $page->save(['voice_map' => 'en-GB | another-voice | m1 | 1.0 | mp3', 'enabled' => '1', 'categories' => [self::NEWS], 'show_player' => '1', 'chunk_cache' => '1']);
        $this->assertSame([], $errors);
        $this->assertSame(Status::OUTDATED_SETTINGS, $this->record($post->ID)->status);
        $this->assertSame([], \WpStub::cronFor('read_aloud_job'));
        $this->assertSame($calls, count($this->provider->requests));
        $this->assertNotSame('', read_aloud_player($post->ID));
        $this->assertSame('unchanged', $this->trigger($post->ID, 'update'));
    }

    /** Test 16 (case 18). */
    public function testNormaliserBumpRebaselines(): void
    {
        $post = $this->approvedPost();
        $modified = $this->approvedPost(['post_title' => 'Second']);
        foreach ([$post, $modified] as $p) {
            $record = $this->plugin->records()->get($p->ID);
            $record['normaliser_version'] = '0';
            $record['content_hash'] = Hashes::content($this->plugin->planner()->spokenText($p), '0');
            $this->plugin->records()->save($p->ID, $record);
        }
        update_option(Maintenance::OPTION_NORMALISER, '0');
        $this->clock->advance(3600);
        $modified->post_modified_gmt = gmdate('Y-m-d H:i:s', $this->clock->now());
        $calls = count($this->provider->requests);

        $this->assertSame(1, $this->plugin->maintenance()->rebaseline());
        $this->assertSame('unchanged', $this->trigger($post->ID, 'update'));
        $this->assertSame('queued', $this->trigger($modified->ID, 'update'));
        $this->assertSame($calls, count($this->provider->requests));
    }

    /** Test 17 (case 19). */
    public function testGenerateNowQueuesUnchangedAndRespectsKillSwitch(): void
    {
        $post = $this->approvedPost();
        $calls = count($this->provider->requests);
        $this->plugin->guard()->setEnabled(false, 'test');
        $this->assertSame('queued', $this->trigger($post->ID, 'generate', ['force' => true, 'fresh' => true]));
        $this->assertSame('stopped_kill', $this->run_($post->ID));
        $this->assertSame($calls, count($this->provider->requests));
        $this->plugin->guard()->setEnabled(true, 'test');
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertGreaterThan($calls, count($this->provider->requests));
        $record = $this->record($post->ID);
        $this->assertNotSame($record->fileKey, $record->pending['file_key'] ?? null, 'a fresh file gets a new URL');
    }

    /** Test 18 (case 20). */
    public function testBulkEditQueuesOneJobEachAndCapMeters(): void
    {
        $this->settings(['cap_generations' => 10]);
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $ids[] = $this->post(['post_title' => 'Story ' . $i])->ID;
        }
        foreach ($ids as $id) {
            $this->assertSame('queued', $this->trigger($id, 'update'));
        }
        $this->assertCount(30, \WpStub::cronFor('read_aloud_job'));
        $this->clock->advance(700);
        $outcomes = $this->plugin->runner()->runDue();
        $this->assertSame(10, $outcomes['done'] ?? 0);
        $this->assertSame(20, $outcomes['capped'] ?? 0);
        $this->assertSame(10, $this->today()['generations']);
    }
}
