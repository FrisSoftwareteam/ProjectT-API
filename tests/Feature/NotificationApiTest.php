<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\DividendDeclaration;
use App\Notifications\DividendWorkflowNotification;
use App\Notifications\InternalAdminNotification;
use App\Services\DividendNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('admin_users');

        parent::tearDown();
    }

    public function test_user_can_list_and_mark_own_notification_as_read(): void
    {
        $user = $this->createAdmin('owner@example.com');
        $otherUser = $this->createAdmin('other@example.com');

        $user->notifyNow(new DividendWorkflowNotification($this->payload('Owner notification')));
        $otherUser->notifyNow(new DividendWorkflowNotification($this->payload('Other notification')));

        $notificationId = $user->unreadNotifications()->firstOrFail()->id;

        $this->withoutMiddleware()
            ->actingAs($user, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.data.title', 'Owner notification');

        $this->withoutMiddleware()
            ->actingAs($user, 'sanctum')
            ->postJson("/api/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('data.read_at', fn ($value) => $value !== null);

        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_user_cannot_modify_another_users_notification(): void
    {
        $user = $this->createAdmin('owner@example.com');
        $otherUser = $this->createAdmin('other@example.com');

        $otherUser->notifyNow(new DividendWorkflowNotification($this->payload('Other notification')));
        $notificationId = $otherUser->unreadNotifications()->firstOrFail()->id;

        $this->withoutMiddleware()
            ->actingAs($user, 'sanctum')
            ->postJson("/api/notifications/{$notificationId}/read")
            ->assertNotFound();

        $this->assertSame(1, $otherUser->unreadNotifications()->count());
    }

    public function test_notification_lookup_failure_does_not_escape_into_workflow(): void
    {
        $declaration = new DividendDeclaration([
            'dividend_declaration_no' => 'TEST-1',
            'initiator' => 'operations',
            'status' => 'SUBMITTED',
            'current_approval_step' => 1,
        ]);
        $declaration->id = 1;

        app(DividendNotificationService::class)->submitted($declaration, 1);

        $this->assertTrue(true);
    }

    public function test_internal_notification_uses_configured_admin_channels(): void
    {
        config()->set('notifications.admin_channels', ['database', 'mail', 'broadcast']);

        $user = $this->createAdmin('channels@example.com');
        $notification = new InternalAdminNotification($this->payload('Configured channels'));

        $this->assertSame(['database', 'mail', 'broadcast'], $notification->via($user));
        $this->assertSame("admin-users.{$user->id}", $user->receivesBroadcastNotificationsOn());
        $this->assertSame($this->payload('Configured channels'), $notification->toBroadcast($user)->data);
        $this->assertSame('Configured channels', $notification->toMail($user)->subject);
    }

    private function createAdmin(string $email): AdminUser
    {
        return AdminUser::query()->create([
            'email' => $email,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'is_active' => true,
        ]);
    }

    private function payload(string $title): array
    {
        return [
            'event' => 'TEST',
            'title' => $title,
            'message' => 'Test notification',
            'entity_type' => 'dividend_declaration',
            'entity_id' => 1,
            'reference' => 'TEST-1',
            'action_url' => '/admin/dividend-declarations/1',
        ];
    }
}
