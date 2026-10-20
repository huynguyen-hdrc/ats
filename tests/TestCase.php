<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Mọi test có sẵn 4 vai trò hệ thống + ma trận quyền mặc định. */
    protected bool $seed = true;
}
