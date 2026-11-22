---
paths:
  - 'app/{Services/FailedJobService.php,Livewire/Platform/Monitoring/FailedJobs.php,Enums/FailedJobAdvice.php}'
---

# Platform Monitoring

## Failed jobs page reads failed_jobs, retries onto the current queue
FailedJobService uses Laravel's failed_jobs table (not Horizon's Redis list) as the single source, so pre-Horizon (database-queue) failures show too. Retry pushes the raw payload onto config('queue.default') with `id`/`attempts` set — never `queue:retry`, which would push old jobs back to the retired database connection. Retry/delete also call Horizon's JobRepository::deleteFailed() so both views agree. advise() matches only the exception's first line (stack-trace line numbers caused false "HTTP 429" matches); unknown causes return Review ("Not sure"), never a guess.
