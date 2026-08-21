<?php

namespace Tests\Unit;

use App\Jobs\PostCscsBatchJob;
use App\Jobs\ProcessCscsImportJob;
use App\Notifications\InternalAdminNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AzureQueueWorkerConfigurationTest extends TestCase
{
    #[Test]
    public function cscs_jobs_are_assigned_to_the_configured_cscs_queue(): void
    {
        config()->set('cscs.queue', 'cscs-test');

        $this->assertSame('cscs-test', (new ProcessCscsImportJob(1))->queue);
        $this->assertSame('cscs-test', (new PostCscsBatchJob(1, 1))->queue);
    }

    #[Test]
    public function internal_admin_notifications_remain_queued(): void
    {
        config()->set('notifications.queue', 'notification-test');

        $notification = new InternalAdminNotification([
            'title' => 'Test',
            'message' => 'Test notification',
            'action_url' => '/',
        ]);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('notification-test', $notification->queue);
        $this->assertSame(3, $notification->tries);
        $this->assertSame(120, $notification->timeout);
    }

    #[Test]
    public function production_release_includes_both_continuous_workers(): void
    {
        $manifest = json_decode(
            file_get_contents(base_path('deploy/production-files.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertContains('App_Data', $manifest['include']);

        foreach (['projectt-cscs-worker', 'projectt-notification-worker'] as $worker) {
            $directory = base_path("App_Data/jobs/continuous/{$worker}");
            $this->assertFileExists("{$directory}/run.sh");
            $this->assertTrue(is_executable("{$directory}/run.sh"));

            $settings = json_decode(
                file_get_contents("{$directory}/settings.job"),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->assertTrue($settings['is_singleton']);
        }
    }

    #[Test]
    public function azure_deployment_enables_required_webjob_runtime_settings(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/main_project-tapi.yml'));

        $this->assertStringContainsString('"webJobsEnabled":true', $workflow);
        $this->assertStringContainsString('WEBSITES_ENABLE_APP_SERVICE_STORAGE=true', $workflow);
        $this->assertStringContainsString('WEBJOBS_STOPPED=0', $workflow);
        $this->assertStringContainsString('--always-on true', $workflow);
    }
}
