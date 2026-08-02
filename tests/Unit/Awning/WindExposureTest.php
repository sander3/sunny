<?php

declare(strict_types=1);

namespace Tests\Unit\Awning;

use App\Awning\WindExposure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WindExposureTest extends TestCase
{
    public function test_it_scales_wind_up_towards_the_mounting_height(): void
    {
        $exposure = new WindExposure(mountingHeightM: 15.0, siteFactor: 1.0);

        $this->assertEqualsWithDelta(1.1067, $exposure->factor(), 0.0001);
        $this->assertEqualsWithDelta(33.2, $exposure->apply(30.0), 0.1);
    }

    public function test_it_never_reports_less_wind_than_the_forecast(): void
    {
        $exposure = new WindExposure(mountingHeightM: 3.0, siteFactor: 1.0);

        $this->assertSame(1.0, $exposure->factor());
        $this->assertSame(30.0, $exposure->apply(30.0));
    }

    public function test_the_site_factor_compounds_with_the_height_factor(): void
    {
        $exposure = new WindExposure(mountingHeightM: 15.0, siteFactor: 1.05);

        $this->assertEqualsWithDelta(1.1621, $exposure->factor(), 0.0001);
    }

    public function test_it_rejects_a_non_positive_mounting_height(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WindExposure(mountingHeightM: 0.0, siteFactor: 1.0);
    }

    public function test_it_rejects_a_non_positive_site_factor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WindExposure(mountingHeightM: 15.0, siteFactor: 0.0);
    }
}
