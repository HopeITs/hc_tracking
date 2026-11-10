{{--
    The Cards view: the viewer's own tasks (assigned to or created by them —
    filteredTasksQuery() narrows to these on this view) as cards in a
    wrapping grid. Reads boardTasks, the capped set the board and calendar share.
--}}
@php $viewerEmployeeId = auth()->user()->employee_id; @endphp
<div class="grid grid-cols-1 gap-3 @xl/directory:grid-cols-2 @4xl/directory:grid-cols-3 @6xl/directory:grid-cols-4" data-test="task-cards-grid">
    @foreach ($this->boardTasks as $cardTask)
        @include('pages.tasks._task-card', ['task' => $cardTask, 'testPrefix' => 'cards-task', 'cardClass' => 'min-w-0'])
    @endforeach
</div>
