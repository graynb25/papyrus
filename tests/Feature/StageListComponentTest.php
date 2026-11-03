<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class StageListComponentTest extends TestCase
{
    /**
     * The shared list is the one place a stage's state is drawn, so each state is checked
     * here rather than only through the pages that pass it a list.
     */
    public function test_the_stage_list_renders_each_state_with_its_own_mark(): void
    {
        $stages = [
            [
                'key' => 'checking_target',
                'status' => 'pending',
                'title' => 'Checking the server',
            ],
            [
                'key' => 'copying_reader_half',
                'status' => 'running',
                'title' => 'Saving your library',
            ],
            [
                'key' => 'copying_manga_half',
                'status' => 'failed',
                'title' => 'Saving your manga data',
                'message' => 'the staging disk filled up',
            ],
            [
                'key' => 'writing_manifest',
                'status' => 'done',
                'title' => 'Checking the backup details',
                'attempts' => 3,
                'message' => 'the manifest was written',
            ],
        ];

        $html = Blade::render('<x-stage-list :stages="$stages" />', ['stages' => $stages]);

        $this->assertStringContainsString('<ol class="mt-4 space-y-2" role="list">', $html);
        $this->assertSame(4, substr_count($html, 'role="listitem"'));

        [$pending, $running, $failed, $done] = array_slice(explode('role="listitem"', $html), 1);

        $this->assertStringContainsString('Checking the server', $pending);
        $this->assertStringContainsString('border-zinc-300 bg-transparent text-transparent', $pending);
        $this->assertStringNotContainsString('&#10003;', $pending);
        $this->assertStringNotContainsString('&#10005;', $pending);
        $this->assertStringNotContainsString('<svg', $pending);

        $this->assertStringContainsString('Saving your library', $running);
        $this->assertStringContainsString('border-zinc-900 bg-zinc-900 text-white', $running);
        $this->assertStringContainsString('size-3', $running);
        $this->assertStringContainsString('<svg', $running);

        $this->assertStringContainsString('Saving your manga data', $failed);
        $this->assertStringContainsString('border-red-500 bg-red-500 text-white', $failed);
        $this->assertStringContainsString('&#10005;', $failed);
        $this->assertStringContainsString('text-red-700 dark:text-red-300', $failed);
        $this->assertStringContainsString('the staging disk filled up', $failed);
        $this->assertStringNotContainsString('tried', $failed);

        $this->assertStringContainsString('Checking the backup details', $done);
        $this->assertStringContainsString('border-zinc-400 bg-zinc-200 text-zinc-700', $done);
        $this->assertStringContainsString('&#10003;', $done);
        $this->assertStringContainsString('tried 3 times', $done);
        $this->assertStringContainsString('the manifest was written', $done);
        $this->assertStringNotContainsString('tried 3 times', $failed);
    }

    public function test_a_single_stage_renders_one_row(): void
    {
        $html = Blade::render('<x-stage-list :stages="$stages" />', ['stages' => [
            ['key' => 'reading', 'status' => 'done', 'title' => 'Reading the file'],
        ]]);

        $this->assertSame(1, substr_count($html, 'role="listitem"'));
        $this->assertStringContainsString('Reading the file', $html);
        $this->assertStringContainsString('&#10003;', $html);
    }

    public function test_an_empty_list_renders_without_a_row(): void
    {
        $html = Blade::render('<x-stage-list :stages="$stages" />', ['stages' => []]);

        $this->assertStringContainsString('role="list"', $html);
        $this->assertSame(0, substr_count($html, 'role="listitem"'));
    }
}
