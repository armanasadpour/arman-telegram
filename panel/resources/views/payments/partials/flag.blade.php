<label class="check"><input type="checkbox" name="{{ str_replace('.', '__', $k) }}" value="1" @checked($flags[$k] ?? false)> {{ $label }}</label>
