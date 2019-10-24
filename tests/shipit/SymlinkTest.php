use namespace HH\Lib\{C, Vec};

    vec<vec<string>>,
        vec[
          vec['git', 'init'],
          vec['touch', 'foo'],
          vec['git', 'add', 'foo'],
          vec['git', 'commit', '-m', 'add file'],
          vec['git', 'rm', 'foo'],
          vec['ln', '-s', 'bar', 'foo'],
          vec['git', 'add', 'foo'],
          vec['git', 'commit', '-m', 'add symlink'],
        ],
        vec[
          vec['hg', 'init'],
          vec['touch', 'foo'],
          vec['hg', 'commit', '-Am', 'add file'],
          vec['hg', 'rm', 'foo'],
          vec['ln', '-s', 'bar', 'foo'],
          vec['hg', 'commit', '-Am', 'add symlink'],
        ],
        vec[
          vec['git', 'init'],
          vec['ln', '-s', 'bar', 'foo'],
          vec['git', 'add', 'foo'],
          vec['git', 'commit', '-m', 'add symlink'],
          vec['git', 'rm', 'foo'],
          vec['touch', 'foo'],
          vec['git', 'add', 'foo'],
          vec['git', 'commit', '-m', 'add file'],
        ],
        vec[
          vec['hg', 'init'],
          vec['ln', '-s', 'bar', 'foo'],
          vec['hg', 'commit', '-Am', 'add symlink'],
          vec['hg', 'rm', 'foo'],
          vec['touch', 'foo'],
          vec['hg', 'commit', '-Am', 'add file'],
        ],
    vec<vec<string>> $steps,
        ->setEnvironmentVariables(dict[
        ])
    \expect(C\count($changeset->getDiffs()))->toBePHPEqual(
    \expect(Vec\map($changeset->getDiffs(), $diff ==> $diff['path']))
      ->toBePHPEqual(
        vec['foo', 'foo'],
        'Expected chunks to affect the same file',
      );