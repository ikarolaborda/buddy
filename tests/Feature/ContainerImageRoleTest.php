<?php

namespace Tests\Feature;

use Tests\TestCase;

/*
 * The dev image serves on :8000 and every deployed environment routes ingress to
 * :8080, so deploying it hangs every request instead of erroring. On 2026-07-27
 * that cost a live outage. The guardrails are a baked-in image role plus a
 * refusal in the dev entrypoint; both are only worth anything if they stay
 * wired, so assert the wiring rather than trusting convention.
 */
class ContainerImageRoleTest extends TestCase
{
    protected function dockerfile(string $path): string
    {
        $full = base_path($path);

        $this->assertFileExists($full, $path.' is missing');

        return (string) file_get_contents($full);
    }

    public function test_dev_dockerfile_marks_itself_as_the_dev_role(): void
    {
        $this->assertStringContainsString(
            'BUDDY_IMAGE_ROLE=dev',
            $this->dockerfile('docker/Dockerfile'),
            'the local-dev image must bake in its role so the entrypoint can refuse a production boot',
        );
    }

    public function test_dev_entrypoint_refuses_a_production_boot(): void
    {
        $entrypoint = $this->dockerfile('docker/entrypoint.sh');

        $this->assertStringContainsString('BUDDY_IMAGE_ROLE', $entrypoint);
        $this->assertStringContainsString('APP_ENV', $entrypoint);
        $this->assertStringContainsString('BUDDY_ALLOW_DEV_IMAGE', $entrypoint);
        $this->assertStringContainsString('exit 1', $entrypoint);
    }

    public function test_production_images_do_not_carry_the_dev_role_or_the_dev_port(): void
    {
        foreach (['docker/production/Dockerfile', 'docker/production/Dockerfile.octane'] as $path) {
            $contents = $this->dockerfile($path);

            $this->assertStringNotContainsString('BUDDY_IMAGE_ROLE=dev', $contents, $path.' must not claim the dev role');
            $this->assertStringNotContainsString('artisan serve', $contents, $path.' must not use the dev server');
            $this->assertStringContainsString('EXPOSE 8080', $contents, $path.' must expose the ingress port');
        }
    }

    public function test_release_build_script_refuses_the_dev_dockerfile(): void
    {
        $script = $this->dockerfile('scripts/build-image.sh');

        $this->assertStringContainsString('docker/production/*', $script, 'the build script must gate on the Dockerfile path');
        $this->assertStringContainsString('refusing to build deployable tag', $script);
    }
}
