<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckOAuthConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'oauth:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Microsoft OAuth configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking Microsoft OAuth Configuration...');
        $this->newLine();

        $clientId = config('services.microsoft.client_id');
        $clientSecret = config('services.microsoft.client_secret');
        $redirectUri = config('services.microsoft.redirect');

        // Check Client ID
        if (empty($clientId)) {
            $this->error('❌ MICROSOFT_CLIENT_ID is not set');
        } else {
            $this->info('✅ MICROSOFT_CLIENT_ID: ' . $clientId);
        }

        // Check Client Secret
        if (empty($clientSecret)) {
            $this->error('❌ MICROSOFT_CLIENT_SECRET is not set');
        } else {
            $secretLength = strlen($clientSecret);
            $secretPreview = substr($clientSecret, 0, 8) . '...' . substr($clientSecret, -4);
            
            $this->info('✅ MICROSOFT_CLIENT_SECRET: ' . $secretPreview . ' (length: ' . $secretLength . ')');
            
            // Check if secret looks valid
            if ($secretLength < 20) {
                $this->warn('⚠️  Warning: Client secret seems too short. Make sure you copied the secret VALUE, not the SECRET ID');
            }
            
            // Check for common issues
            if (strpos($clientSecret, ' ') !== false) {
                $this->warn('⚠️  Warning: Client secret contains spaces. This might cause issues.');
            }
            
            // Check if it looks like a UUID (which would be the Secret ID, not the value)
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $clientSecret)) {
                $this->error('❌ ERROR: This looks like a Secret ID, not the secret VALUE!');
                $this->warn('   Go to Azure Portal → App registrations → Certificates & secrets');
                $this->warn('   Use the VALUE column, not the Secret ID column');
            }
        }

        // Check Redirect URI
        if (empty($redirectUri)) {
            $this->error('❌ MICROSOFT_REDIRECT_URI is not set');
        } else {
            $this->info('✅ MICROSOFT_REDIRECT_URI: ' . $redirectUri);
            
            // Check if it's using localhost (development)
            if (strpos($redirectUri, 'localhost') !== false || strpos($redirectUri, '127.0.0.1') !== false) {
                $this->warn('⚠️  Using localhost URL (development mode)');
            }
            
            // Check if it's using HTTPS (production)
            if (strpos($redirectUri, 'https://') === 0) {
                $this->info('   ✓ Using HTTPS (production ready)');
            } else {
                $this->warn('   ⚠️  Not using HTTPS (only use HTTP for local development)');
            }
        }

        $this->newLine();

        // Summary
        $allConfigured = !empty($clientId) && !empty($clientSecret) && !empty($redirectUri);
        
        if ($allConfigured) {
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info('✅ All Microsoft OAuth settings are configured!');
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->newLine();
            $this->info('Next steps:');
            $this->line('1. Verify these settings match your Azure Portal configuration');
            $this->line('2. Ensure the Redirect URI is added to Azure Portal exactly as shown above');
            $this->line('3. Test the OAuth flow: php artisan serve');
            $this->line('4. Visit: http://localhost:8000/api/auth/microsoft/redirect');
        } else {
            $this->error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->error('❌ OAuth configuration is incomplete!');
            $this->error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->newLine();
            $this->info('Please add the following to your .env file:');
            $this->newLine();
            $this->line('MICROSOFT_CLIENT_ID=your_client_id_here');
            $this->line('MICROSOFT_CLIENT_SECRET="your_client_secret_here"');
            $this->line('MICROSOFT_REDIRECT_URI=http://localhost:8000/api/auth/microsoft/callback');
            $this->newLine();
            $this->info('Then run: php artisan config:clear');
        }

        return 0;
    }
}
