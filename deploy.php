<?php
namespace Deployer;

require 'recipe/symfony.php';

// Run with
// ~/.composer/vendor/bin/dep deploy -o remote_user=admin -o become=root
// REQUIRES DEPLOYER TO BE INSTALLED GLOBALLY:
// composer global require deployer/deployer

// Config

set('repository', 'git@github.com:Oimmei-Digital-Boutique/AgroalimentareServer.git');
set('default_timeout', 3600);
set('http_user', 'www-data');
set('writable_mode', 'chown');
set('keep_releases', 3);

add('shared_files', []);
add('shared_dirs', [
    'public/uploads',
    'public/import',
    'config/jwt',
]);
add('writable_dirs', [
    'public/uploads',
]);

set('env', [
    'COMPOSER_ALLOW_SUPERUSER' => 1,
]);

// Hosts

host('3.125.68.183')
    ->set('deploy_path', '/var/www/api.maremmatirrenoitinerari.it')
    ->set('writable_recursive', true)
;

// Tasks

task('database:migrate', function () {
    within('{{release_path}}', function () {
        run('php bin/console doctrine:migrations:migrate --no-interaction');
    });
});

task('composer:dump-autoload', function () {
    within('{{release_path}}', function () {
        run('{{deploy_path}}/.dep/composer.phar dump-autoload --no-dev --classmap-authoritative --no-interaction');
    });
});

task('php-fpm:restart', function () {
    // Restarting PHP to load the new container file.
    run('sudo systemctl restart php8.2-fpm.service');
});

after('deploy:failed', 'deploy:unlock');
before('deploy:symlink', 'composer:dump-autoload');
after('deploy:cleanup', 'php-fpm:restart');

after('deploy:symlink', 'database:migrate');

// This is a fix for the base v7 recipe, that doesn't make the cache
// and sessions writable as they should after clearing them up.
before('deploy:unlock', 'deploy:writable');
