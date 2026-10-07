<?php

namespace Tests\Feature\Admin;

use App\Models\PromotionNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The announcement admin screens.
 *
 * Both the list and the edit form call `->format()` on `starts_at` and
 * `ends_at`. The model had no `$casts`, so those columns came back from MySQL as
 * plain strings and the list page died with:
 *
 *     Call to a member function format() on string
 *
 * `created_at`/`updated_at` hid the problem — Eloquent casts those by default,
 * so a model with no `$casts` still appears to handle dates correctly until a
 * custom timestamp column is used.
 */
class AnnouncementAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function announcement(array $attributes = []): PromotionNotification
    {
        return PromotionNotification::create(array_merge([
            'type' => 'promotion',
            'title' => 'Double data weekend',
            'content' => 'Buy any 1GB plan and get 1GB free.',
            'is_active' => true,
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->addDays(7)->endOfDay(),
        ], $attributes));
    }

    public function test_the_timestamp_columns_are_cast_to_dates(): void
    {
        // The root cause, asserted directly.
        $announcement = $this->announcement();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $announcement->starts_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $announcement->ends_at);
        $this->assertIsBool($announcement->is_active);
    }

    public function test_the_index_renders_an_announcement_with_both_dates(): void
    {
        // The exact branch that threw: both columns populated.
        $announcement = $this->announcement();

        $this->actingAs($this->admin())
            ->get(route('admin.announcement.index'))
            ->assertOk()
            ->assertSee('Double data weekend')
            ->assertSee($announcement->starts_at->format('M d, Y'))
            ->assertSee($announcement->ends_at->format('M d, Y'));
    }

    public function test_the_index_renders_an_announcement_with_only_a_start_date(): void
    {
        // The `elseif` branch, which formats a single date.
        $announcement = $this->announcement(['ends_at' => null]);

        $this->actingAs($this->admin())
            ->get(route('admin.announcement.index'))
            ->assertOk()
            ->assertSee('Starts ' . $announcement->starts_at->format('M d, Y'));
    }

    public function test_the_index_renders_an_announcement_with_no_schedule(): void
    {
        $this->announcement(['starts_at' => null, 'ends_at' => null]);

        $this->actingAs($this->admin())
            ->get(route('admin.announcement.index'))
            ->assertOk()
            ->assertSee('No schedule');
    }

    public function test_the_edit_form_prefills_the_datetime_inputs(): void
    {
        // The form formats the same columns into `datetime-local` values, which
        // needs the Y-m-d\TH:i shape — a raw Carbon string would fail silently
        // and blank the field.
        $announcement = $this->announcement();

        $this->actingAs($this->admin())
            ->get(route('admin.announcement.edit', $announcement->id))
            ->assertOk()
            ->assertSee($announcement->starts_at->format('Y-m-d\TH:i'), false)
            ->assertSee($announcement->ends_at->format('Y-m-d\TH:i'), false);
    }

    public function test_the_create_form_renders_without_an_announcement(): void
    {
        // The form is shared between create and edit and guards with
        // `isset($announcement)`, so the empty case needs its own check.
        $this->actingAs($this->admin())
            ->get(route('admin.announcement.create'))
            ->assertOk();
    }
}
