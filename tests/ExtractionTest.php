<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Text\Extractor;
use IndianOpinion\ReadAloud\Text\Hashes;
use IndianOpinion\ReadAloud\Text\Lexicon;
use IndianOpinion\ReadAloud\Text\Normaliser;

/**
 * PRD 12.3, cases 21 to 33.
 */
final class ExtractionTest extends TestCase
{
    private function extract(string $content, string $title = 'Title', array $options = []): string
    {
        $extractor = new Extractor(
            new Normaliser(),
            $options['skip'] ?? Extractor::DEFAULT_SKIP_BLOCKS,
            $options['keep'] ?? [],
            $options['tables'] ?? false,
            $options['captions'] ?? false
        );

        return $extractor->extract($title, $content);
    }

    /** Test 19 (cases 21, 23). */
    public function testContentFiltersDoNotReachTheSpokenText(): void
    {
        $post = $this->post();
        $before = read_aloud_spoken_text($post->ID);
        add_filter('the_content', fn (string $c): string => $c . '<div class="share">Share this. Posted 3 hours ago.</div>');
        $after = read_aloud_spoken_text($post->ID);
        $this->assertSame($before, $after);
        $this->assertStringNotContainsString('Share this', $after);

        $dynamic = "<!-- wp:latest-posts {\"postsToShow\":3} /-->\n<!-- wp:paragraph --><p>Real words.</p><!-- /wp:paragraph -->";
        $this->assertSame("Title.\nReal words.", $this->extract($dynamic));
        $withInner = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Inside a group.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
        $this->assertSame("Title.\nInside a group.", $this->extract($withInner));
    }

    /** Test 20 (case 22). */
    public function testSkippedBlocks(): void
    {
        $content = self::blocks(['Keep one.'])
            . "<!-- wp:code --><pre class=\"wp-block-code\"><code>echo 1;</code></pre><!-- /wp:code -->\n"
            . "<!-- wp:embed {\"url\":\"https://video.example\"} --><figure class=\"wp-block-embed\"><div>https://video.example</div></figure><!-- /wp:embed -->\n"
            . "<!-- wp:image --><figure class=\"wp-block-image\"><img src=\"a.jpg\" alt=\"A cat\"/><figcaption>Cat caption</figcaption></figure><!-- /wp:image -->\n"
            . "<!-- wp:table --><figure class=\"wp-block-table\"><table><tr><td>Cell text</td></tr></table></figure><!-- /wp:table -->\n"
            . "<!-- wp:html --><div>Custom html</div><!-- /wp:html -->\n"
            . "<!-- wp:read-aloud/player /-->\n"
            . "<!-- wp:paragraph {\"className\":\"read-aloud-skip\"} --><p class=\"read-aloud-skip\">Skip me.</p><!-- /wp:paragraph -->\n"
            . "<!-- wp:quote --><blockquote class=\"wp-block-quote\"><!-- wp:paragraph --><p>Quoted words.</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->\n"
            . self::blocks(['Keep two.']);
        $text = $this->extract($content);
        $this->assertSame("Title.\nKeep one.\nQuoted words.\nKeep two.", $text);

        $withTables = $this->extract($content, 'Title', ['tables' => true]);
        $this->assertStringContainsString('Cell text', $withTables);

        $post = $this->post(['post_content' => $content]);
        add_filter('read_aloud_skip_block_types', fn (array $types): array => array_merge($types, ['core/quote']));
        $this->assertStringNotContainsString('Quoted words', read_aloud_spoken_text($post->ID));
    }

    /** Test 21 (case 24). */
    public function testShortcodesRemovedUnlessKept(): void
    {
        $content = '<p>Before [gallery ids="1,2"] middle [caption id="3"]Caption words[/caption] after [Editor\'s note: kept].</p>';
        $this->assertSame("Title.\nBefore middle after [Editor's note: kept].", $this->extract($content));
        $kept = $this->extract($content, 'Title', ['keep' => ['caption']]);
        $this->assertStringContainsString('Caption words', $kept);
        $this->assertStringNotContainsString('[caption', $kept);
    }

    /** Test 22 (case 25). */
    public function testClassicContent(): void
    {
        $content = "First paragraph here\n\nSecond paragraph <em>with</em> emphasis\n\n<pre>code block</pre>\n\n"
            . "<script>alert(1)</script><style>p{}</style>\n\n<figure><img src=\"x.jpg\"><figcaption>Photo caption</figcaption></figure>\n\n"
            . "<p class=\"read-aloud-skip\">Hidden</p><iframe src=\"https://e.example\"></iframe>Third one";
        $this->assertSame("Title.\nFirst paragraph here.\nSecond paragraph with emphasis.\nThird one.", $this->extract($content));
        $this->assertStringContainsString('Photo caption.', $this->extract($content, 'Title', ['captions' => true]));
    }

    /** Test 23 (case 26). */
    public function testEntitiesUrlsAndEmails(): void
    {
        $content = '<p>Tom &amp; Jerry said &ldquo;hello&rdquo; at <a href="https://example.org/x">the club site</a>, see https://example.org/page or www.example.org and mail hello@example.org now</p>';
        $this->assertSame("Title.\nTom & Jerry said \u{201C}hello\u{201D} at the club site, see or and mail now.", $this->extract($content));
    }

    /** Test 24 (case 27). */
    public function testHeadingsAndListItemsEndWithFullStop(): void
    {
        $content = '<h2>A heading</h2><ul><li>First item</li><li>Second item!</li></ul><p>Done?</p><h3>Already: </h3><p>Hindi line।</p>';
        $this->assertSame("Title.\nA heading.\nFirst item.\nSecond item!\nDone?\nAlready:\nHindi line\u{0964}", $this->extract($content));
    }

    /** Test 25 (case 28). */
    public function testInvisibleCharactersAndNfc(): void
    {
        $content = "<p>Zero\u{200B}width soft\u{00AD}hyphen smile \u{1F600}\u{1F44D}\u{1F3FD} flag \u{1F1EE}\u{1F1F3} heart \u{2764}\u{FE0F} end</p>";
        $this->assertSame("Title.\nZerowidth softhyphen smile flag heart end.", $this->extract($content));
        $nfc = '<p>' . "Caf\u{00E9} na\u{00EF}ve" . '</p>';
        $nfd = '<p>' . "Cafe\u{0301} nai\u{0308}ve" . '</p>';
        $this->assertSame($this->extract($nfc), $this->extract($nfd));
        $this->assertSame(Hashes::content($this->extract($nfc)), Hashes::content($this->extract($nfd)));
        // Joiners inside an Indic word are kept; a lone one is dropped.
        $this->assertSame("Title.\nक्\u{200D}ष ok.", $this->extract("<p>क्\u{200D}ष \u{200D}ok</p>"));
    }

    /** Test 26 (case 29). */
    public function testCaptionsDroppedByDefault(): void
    {
        $content = '<!-- wp:image --><figure class="wp-block-image"><img src="a.jpg"/><figcaption>A boat at dawn</figcaption></figure><!-- /wp:image -->'
            . self::blocks(['Body text.']);
        $this->assertSame("Title.\nBody text.", $this->extract($content));
        $this->assertSame("Title.\nA boat at dawn.\nBody text.", $this->extract($content, 'Title', ['captions' => true]));
        $footnoted = '<p>Claim<sup data-fn="abc" class="fn"><a href="#abc">1</a></sup> made.</p>';
        $this->assertSame("Title.\nClaim made.", $this->extract($footnoted));
    }

    /** Test 27 (case 30). */
    public function testIntroChangesRenderHashOnly(): void
    {
        $post = $this->approvedPost();
        $record = $this->record($post->ID);
        $this->settings(['intro' => 'You are listening to the Daily.']);
        $plan = $this->plugin->planner()->plan($post);
        $this->assertSame($record->contentHash, $plan->contentHash);
        $this->assertNotSame($record->renderHash, $plan->renderHash);
        $this->assertSame('unchanged', $this->trigger($post->ID, 'update'));
        $this->assertStringStartsWith('You are listening', $this->plugin->planner()->synthesisText($plan->text));
    }

    /** Test 28 (case 31). */
    public function testDeterministicAcrossRunsAndScripts(): void
    {
        $fixtures = [
            'latin' => '<p>The quick brown fox, jumps over the lazy dog.</p><h2>Résumé</h2>',
            'devanagari' => '<p>भारत एक विशाल देश है। यहाँ अनेक भाषाएँ बोली जाती हैं।</p><p>नई दिल्ली राजधानी है</p>',
            'tamil' => '<p>தமிழ் ஒரு பழமையான மொழி. சென்னை ஒரு பெரிய நகரம்</p>',
            'arabic' => '<p>اللغة العربية جميلة؟ نعم</p><p>مرحبا بالعالم</p>',
        ];
        foreach ($fixtures as $name => $html) {
            $first = $this->extract($html, $name);
            $hash = Hashes::content($first);
            for ($i = 0; $i < 1000; $i++) {
                $again = $this->extract($html, $name);
                if ($again !== $first || Hashes::content($again) !== $hash) {
                    $this->fail('Output changed on run ' . $i . ' for ' . $name);
                }
            }
        }
        $this->assertSame("devanagari.\nभारत एक विशाल देश है। यहाँ अनेक भाषाएँ बोली जाती हैं।\nनई दिल्ली राजधानी है.", $this->extract($fixtures['devanagari'], 'devanagari'));
        $this->assertSame("arabic.\nاللغة العربية جميلة؟ نعم.\nمرحبا بالعالم.", $this->extract($fixtures['arabic'], 'arabic'));
    }

    /** Test 29 (case 32). */
    public function testSpokenTextFilterOutputIsHashed(): void
    {
        $post = $this->post();
        add_filter('read_aloud_spoken_text', fn (string $text): string => $text . "\nAppended by a filter.");
        $plan = $this->plugin->planner()->plan($post);
        $this->assertStringEndsWith('Appended by a filter.', $plan->text);
        $this->assertSame(Hashes::content($plan->text), $plan->contentHash);
    }

    /** Test 30 (case 33). */
    public function testLexiconWholeWordsOnly(): void
    {
        $lexicon = new Lexicon([
            ['written' => 'NHS', 'spoken' => 'N H S', 'case_sensitive' => true],
            ['written' => 'gif', 'spoken' => 'jif', 'case_sensitive' => false],
            ['written' => 'भारत', 'spoken' => 'भा-रत'],
        ]);
        $this->assertSame('The N H S and NHSX, jif and jif but gifts.', $lexicon->apply('The NHS and NHSX, gif and GIF but gifts.'));
        $this->assertSame('भा-रत और भारतीय', $lexicon->apply('भारत और भारतीय'));
        $this->assertSame('No {voice} change', $lexicon->apply('No {voice} change'));

        $post = $this->post();
        $before = $this->plugin->planner()->plan($post);
        $this->settings(['lexicon' => [['written' => 'council', 'spoken' => 'COUNCIL', 'case_sensitive' => true]]]);
        $after = $this->plugin->planner()->plan($post);
        $this->assertSame($before->contentHash, $after->contentHash);
        $this->assertNotSame($before->renderHash, $after->renderHash);
        $this->assertStringContainsString('COUNCIL', $this->plugin->planner()->synthesisText($after->text));
    }
}
