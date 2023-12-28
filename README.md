# laravel-apollo-client

- This is a laravel package for apollo client. It can be used to get the configuration of apollo server.
- Inspire By [multilinguals/apollo-php-client](https://github.com/multilinguals/apollo-php-client).
- Read the [Apollo Docs](https://www.apolloconfig.com/#/zh/README) for more information.
## install
```shell
composer require timefactory/laravel-apollo-client
```

## config app/Console/Kernel.php
```php
    protected $commands = [
        Timefactory\Apollo\Command\StartApolloAgent::class
    ];
```

## start apollo agent
```shell
php artisan apollo:start --server=http://apollo.server.url --appid=demo-api --cluster=default --namespaces=application,mysql,redis --daemon=true
```
