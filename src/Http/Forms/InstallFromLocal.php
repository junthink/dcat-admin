<?php

namespace Dcat\Admin\Http\Forms;

use Dcat\Admin\Admin;
use Dcat\Admin\Contracts\LazyRenderable;
use Dcat\Admin\Exception\RuntimeException;
use Dcat\Admin\Traits\LazyWidget;
use Dcat\Admin\Traits\RequiresAdministrator;
use Dcat\Admin\Widgets\Form;

class InstallFromLocal extends Form implements LazyRenderable
{
    use LazyWidget;
    use RequiresAdministrator;

    public function handle(array $input)
    {
        $file = $input['extension'];

        if (! $file) {
            return $this->response()->error('Invalid arguments.');
        }

        try {
            $path = $this->getFilePath($file);

            $manager = Admin::extension();

            $extensionName = $manager->extract($path, true);

            if (! $extensionName) {
                return $this->response()->error(trans('admin.invalid_extension_package'));
            }

            $manager
                ->load()
                ->updateManager()
                ->update($extensionName);

            return $this->response()
                ->success(implode('<br>', $manager->updateManager()->notes))
                ->refresh();
        } catch (\Throwable $e) {
            Admin::reportException($e);

            return $this->response()->error($e->getMessage());
        } finally {
            if (! empty($path)) {
                @unlink($path);
            }
        }
    }

    public function form()
    {
        $this->file('extension')
            ->required()
            ->disk($this->disk())
            ->accept('zip', 'application/zip')
            ->autoUpload();
    }

    protected function getFilePath($file)
    {
        $root = config("filesystems.disks.{$this->disk()}.root");

        if (! $root) {
            throw new RuntimeException(sprintf('Missing \'root\' for disk [%s].', $this->disk()));
        }

        $root = realpath($root);
        $path = is_string($file) && $root ? realpath($root.'/'.$file) : false;
        if (! $path || ! is_file($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)
            || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Invalid extension archive path.');
        }

        return $path;
    }

    protected function disk()
    {
        return config('admin.extension.disk') ?: 'local';
    }
}
