<?php
/**
 * This script generates the matrix for the Docker image build job
 *
 * It checks if changes in the DokuWiki branch, the upstream PHP image or the docker repo have been made
 * since it ran last time (using the github action cache for persistence). This allows us to run this every
 * day to catch any updates to the upstream images, while avoiding unnecessary rebuilds.
 */


/**
 * Fetch a URL and return the decoded JSON response
 *
 * Aborts the script with an exception when the request fails or the response
 * is not valid JSON, so that transient network or API errors surface as a
 * failed job instead of an empty build matrix.
 *
 * @param string $url the URL to request
 * @param resource|null $context an optional stream context for the request
 * @return array the decoded JSON response
 * @throws RuntimeException when the request fails or returns invalid JSON
 */
function fetchJson($url, $context = null)
{
    $data = @file_get_contents($url, false, $context);
    if ($data === false) {
        $error = error_get_last();
        throw new RuntimeException("Failed to fetch $url: " . ($error['message'] ?? 'unknown error'));
    }
    try {
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Invalid JSON from $url: " . $e->getMessage());
    }
}

/**
 * Get the list of DokuWiki versions from the download server
 *
 * @return array
 * @throws RuntimeException when the versions cannot be fetched
 */
function getVersions()
{
    return fetchJson('https://download.dokuwiki.org/version');
}

/**
 * Get the last commit of a branch
 *
 * @param string $repo
 * @param string $branch
 * @return string
 * @throws RuntimeException when the commit cannot be determined
 */
function getLastCommit($repo, $branch)
{
    $opts = [
        'http' => [
            'method' => "GET",
            'header' => join("\r\n", [
                "Accept: application/vnd.github.v3+json",
                "User-Agent: PHP"
            ])
        ]
    ];
    $context = stream_context_create($opts);

    $url = "https://api.github.com/repos/dokuwiki/$repo/commits/$branch";
    $json = fetchJson($url, $context);
    if (!isset($json['sha'])) {
        throw new RuntimeException("No commit sha in response from $url");
    }
    return $json['sha'];
}

/**
 * Get the image id of a given PHP image tag
 *
 * @param string $tag
 * @return string
 * @throws RuntimeException when the image id cannot be determined
 */
function getImageId($tag)
{
    $repo = 'library/php';
    $token = fetchJson('https://auth.docker.io/token?service=registry.docker.io&scope=repository:' . $repo . ':pull')['token'] ?? null;
    if ($token === null) {
        throw new RuntimeException('Failed to obtain a Docker registry token');
    }

    $opts = [
        'http' => [
            'method' => "GET",
            'header' => join("\r\n", [
                "Authorization: Bearer $token",
                "Accept: application/vnd.oci.image.index.v1+json",
                "Accept: application/vnd.oci.image.manifest.v1+json",
                "Accept: application/vnd.docker.distribution.manifest.list.v2+json",
                "Accept: application/vnd.docker.distribution.manifest.v2+json",
            ])
        ]
    ];
    $context = stream_context_create($opts);

    $url = 'https://index.docker.io/v2/' . $repo . '/manifests/' . $tag;
    $json = fetchJson($url, $context);
    // manifest list / OCI index: digest is per-platform in manifests[]; single manifest: config.digest
    if (isset($json['manifests'])) {
        $digests = array_map(fn($m) => $m['digest'], $json['manifests']);
        sort($digests);
        return implode(',', $digests);
    }
    if (!isset($json['config']['digest'])) {
        throw new RuntimeException("No image digest in manifest from $url");
    }
    return $json['config']['digest'];
}

/**
 * Get the image tag used in the current Dockerfile
 *
 * @return string
 * @throws RuntimeException when no PHP image tag can be found
 */
function getImageTag()
{
    $df = @file_get_contents('Dockerfile');
    if ($df === false) {
        throw new RuntimeException('Failed to read Dockerfile');
    }
    if (!preg_match('/FROM php:(?<tag>\S*)/', $df, $matches)) {
        throw new RuntimeException('Could not find a PHP image tag in the Dockerfile');
    }
    return $matches['tag'];
}


try {
    $result = [];
    $pending = [];
    $self = getLastCommit('docker', 'main');
    $upstreamTag = getImageTag();
    $image = getImageId($upstreamTag);

    foreach (getVersions() as $release => $info) {
        $branch = $release === 'oldstable' ? 'old-stable' : $release;
        $commit = getLastCommit('dokuwiki', $branch);
        $ident = join('-', [$release, $commit, $image, $self]);
        $cache = '.github/matrix.cache/' . $release;

        $last = @file_get_contents($cache);
        fwrite(STDERR, "Old: $last\n");
        fwrite(STDERR, "New: $ident\n");
        if ($last === $ident) {
            // this combination has been built before
            fwrite(STDERR, "No change. Skipping $release\n");
            continue;
        }

        // this branch needs to be built
        $result[] = [
            'version' => $info['version'],
            'date' => $info['date'],
            'name' => $info['name'],
            'type' => $release,
        ];
        // remember the cache update, only written once the whole matrix computed cleanly
        $pending[$cache] = $ident;
    }

    // the matrix is complete, persist the cache so a partial failure never marks
    // a release as built
    if ($pending && !is_dir('.github/matrix.cache')) {
        mkdir('.github/matrix.cache');
    }
    foreach ($pending as $cache => $ident) {
        file_put_contents($cache, $ident);
    }
} catch (Throwable $e) {
    // abort with a non-zero exit code so the build job is not silently skipped
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

// output the result
if ($result) {
    echo "matrix=" . json_encode(['release' => $result]);
} else {
    echo "matrix=[]";
}

