# Cache development mode

Development mode temporarily bypasses response caching for an entire domain. It is intended for bounded troubleshooting and content iteration, not as a permanent hidden setting.

From a domain page choose **Enable development mode**, enter a duration from 1 through 1,440 minutes, and confirm. The absolute expiry is stored in PostgreSQL, included in the signed domain revision, and displayed in the domain's Cache section. OpenResty evaluates the absolute timestamp on every request, so bypass ends automatically even if the scheduler or control plane is unavailable.

While active, qualified requests return `X-CDNFoundry-Cache: BYPASS`. Choose **Disable development mode** to end it early. Both transitions increment the domain revision and use normal asynchronous edge delivery; the previous valid runtime state remains active until the replacement is acknowledged.
