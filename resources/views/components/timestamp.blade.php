<time {{ $attributes->merge(['datetime' => $value->format(DATE_ATOM)]) }}>{{ $formatted() }}</time>
