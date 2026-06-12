<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->get('about', 'Pages::about');
$routes->get('posts', 'Posts::index');
$routes->get('posts/(:segment)', 'Posts::show/$1');

service('auth')->routes($routes);
