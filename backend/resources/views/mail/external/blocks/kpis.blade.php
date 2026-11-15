@foreach ($m['kpi_groups'] as $g)
    @include('mail.digest._group', ['g' => $g])
@endforeach
