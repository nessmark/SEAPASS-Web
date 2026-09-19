<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Thrown when requested seats are already booked or unavailable.
 */
class SeatUnavailableException extends Exception
{
    /**
     * @var array<int, string>
     */
    protected array $unavailableSeats;

    /**
     * @param string $message
     * @param array<int, string> $unavailableSeats
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(string $message, array $unavailableSeats = [], int $code = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->unavailableSeats = $unavailableSeats;
    }

    /**
     * Get the list of unavailable seat numbers.
     *
     * @return array<int, string>
     */
    public function getUnavailableSeats(): array
    {
        return $this->unavailableSeats;
    }
}
