<?php

declare(strict_types=1);

namespace LaravelProDocs\Tests\Integration;

use LaravelProDocs\Commands\BuildIndexCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class BuildIndexCommandTest extends TestCase
{
    private string $tempRepo;
    private string $outputJson;

    protected function setUp(): void
    {
        $this->tempRepo = sys_get_temp_dir() . '/test_framework_repo_' . uniqid();
        $this->outputJson = sys_get_temp_dir() . '/symbols_' . uniqid() . '.json';

        mkdir($this->tempRepo . '/src/Illuminate/Support', 0755, true);

        // Initialize git repo
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " init --initial-branch=10.x");
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " config user.name 'Laravel Test'");
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " config user.email 'test@laravel.com'");

        // Commit base release v9.0.0
        $helpersContent = "<?php\n\nfunction str() {}\nfunction rescue() {}\n";
        file_put_contents($this->tempRepo . '/src/Illuminate/Support/helpers.php', $helpersContent);
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " add .");
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " commit -m 'Initial commit'");
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " tag v9.0.0");

        // Commit feature release v10.38.0
        $numberContent = <<<'PHP'
<?php

namespace Illuminate\Support;

class Number
{
    public static function currency(float|int $number, string $in = 'USD'): string
    {
        return '$100';
    }
}
PHP;
        file_put_contents($this->tempRepo . '/src/Illuminate/Support/Number.php', $numberContent);
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " add .");
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " commit -m '[10.x] Add Number::currency helper (#49451)'");
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " tag v10.38.0");
    }

    protected function tearDown(): void
    {
        // Cleanup temp repo
        shell_exec('rm -rf ' . escapeshellarg($this->tempRepo));
        if (file_exists($this->outputJson)) {
            unlink($this->outputJson);
        }
    }

    public function testBuildIndexCommandExecutesSuccessfully(): void
    {
        $app = new Application();
        $app->add(new BuildIndexCommand());

        $command = $app->find('build:index');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--framework-path' => $this->tempRepo,
            '--from-tag' => 'v9.0.0',
            '--output' => $this->outputJson,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->outputJson);

        $json = json_decode((string) file_get_contents($this->outputJson), true);
        $this->assertIsArray($json);

        // Check base v9.0.0 helper
        $this->assertArrayHasKey('str', $json);
        $this->assertSame('v9.0.0', $json['str']['version']);

        // Check v10.38.0 Number::currency with PR
        $this->assertArrayHasKey('Number::currency', $json);
        $this->assertSame('v10.38.0', $json['Number::currency']['version']);
        $this->assertSame(49451, $json['Number::currency']['pr']);
        $this->assertSame('https://github.com/laravel/framework/pull/49451', $json['Number::currency']['pr_url']);
    }

    public function testResumeReusesExistingIndexAndPreservesEarliestVersions(): void
    {
        $app = new Application();
        $app->add(new BuildIndexCommand());
        $command = $app->find('build:index');
        $tester = new CommandTester($command);

        // Initial build covering only the base tag.
        $exitCode = $tester->execute([
            '--framework-path' => $this->tempRepo,
            '--from-tag' => 'v9.0.0',
            '--to-tag' => 'v9.0.0',
            '--output' => $this->outputJson,
        ]);
        $this->assertSame(0, $exitCode);

        // New release with a new symbol.
        $numberContent = <<<'PHP'
<?php

namespace Illuminate\Support;

class Widget
{
    public static function sprocket(): string
    {
        return 'sprocket';
    }
}
PHP;
        file_put_contents($this->tempRepo . '/src/Illuminate/Support/Widget.php', $numberContent);
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " add .");
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " commit -m '[10.x] Add Widget::sprocket (#12345)'");
        shell_exec("git -C " . escapeshellarg($this->tempRepo) . " tag v10.0.0");

        // Resume: must pick up the new symbol while keeping base versions intact.
        $exitCode = $tester->execute([
            '--framework-path' => $this->tempRepo,
            '--output' => $this->outputJson,
            '--resume' => true,
        ]);
        $this->assertSame(0, $exitCode);

        $json = json_decode((string) file_get_contents($this->outputJson), true);
        $this->assertIsArray($json);
        $this->assertSame('v9.0.0', $json['str']['version']);
        $this->assertArrayHasKey('Widget::sprocket', $json);
        $this->assertSame('v10.0.0', $json['Widget::sprocket']['version']);
        $this->assertSame(12345, $json['Widget::sprocket']['pr']);
    }

    public function testFailsWhenFrameworkPathIsMissingOrInvalid(): void
    {
        $app = new Application();
        $app->add(new BuildIndexCommand());

        $command = $app->find('build:index');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--framework-path' => '/non/existent/path',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not a valid git repository', $tester->getDisplay());
    }
}
