<?php

namespace App\View\Components;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\View\Component;

class Timestamp extends Component
{
    public function __construct(
        public DateTimeInterface $value,
        public string $format = 'M j, Y g:i A T',
    ) {}

    /**
     * Render the value in the timezone the signed-in user reads times in.
     */
    public function formatted(): string
    {
        return Carbon::instance($this->value)
            ->setTimezone($this->displayTimezone())
            ->format($this->format);
    }

    public function render(): View
    {
        return view('components.timestamp');
    }

    /**
     * The display timezone only affects rendering; schedules never use it.
     */
    private function displayTimezone(): string
    {
        $user = Auth::user();

        return $user instanceof User
            ? $user->timezone
            : Config::string('app.timezone');
    }
}
