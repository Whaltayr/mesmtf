<?php

declare(strict_types=1);
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
$q = trim((string)($_GET['doctorsearch'] ?? ''));

$selected_doctor_id = isset($_GET['selected_doctor']) && ctype_digit($_GET['selected_doctor']) ? (int)$_GET['selected_doctor'] : 0;
$selected_doctor_name = trim((string)($_GET['doctor_name'] ?? ''));
$apiBase = 'http://localhost/doctor';
$apiUrl = $apiBase . '/api/doctors.php?action=search&q=' . urlencode($q) . '&limit=50';

$rows = [];
$raw = @file_get_contents($apiUrl);
if ($raw !== false) {
    $json = json_decode($raw, true);
    $rows = $json['data']['rows'] ?? [];
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments</title>
    <style>.wrapper{
        display: flex;
        gap: 4rem;
        padding: 2rem;
        margin: 0 auto;
    }

    </style>
</head>

<body>
    <main>
        <div class="wrapper">
            <div class="search-panel">
                <!-- form for searching -->
                <form action="" method="get">
                    <h4>Search for Doctors</h4>
                    <div class="searchbar-conteiner">
                        <input type="search" name="doctorsearch" value="<?php echo h($q); ?>">
                        <button type="submit">Search</button>
                    </div>
                    <div class="searchresults">
                        <?php if ($q !== '' && empty($rows)): ?>
                            <p>No matches found "<?php echo h($q); ?>".</p>
                        <?php endif; ?>

                        <?php foreach ($rows as $doc): ?>
                            <div class="searchresults-card">
                                <div class="searchres-info">
                                    <h4><?php echo h($doc['full_name'] ?? ''); ?></h4>
                                    <p><?php echo h($doc['specialty'] ?? ''); ?></p>
                                </div>
                                <div class="searchres-btn">
                                    <a href="?doctorsearch=<?php echo urlencode($q); ?>&selected_doctor=<?php echo (int)$doc['id']; ?>&doctor_name=<?php echo urlencode($doc['full_name'] ?? ''); ?>">
                                        Select
                                            </a>
                                </div>
                            </div>
                        <?php endforeach ?>
                    </div>
                </form>
                <!-- form for upcoming books -->
                <form action="" method="get">
                    <h4>upcoming</h4>

                    <div class="upcomingres">
                        <div class="upcomingres-card">
                            <div class="upcoming-info">
                                <h4>patient name -|- Doctor name</h4>
                                <p>date and time of th e appointment - duration - status</p>
                            </div>
                            <button type="submit" class="upcomin-btn">cancel</button>
                        </div>
                    </div>
                </form>


            </div>
            <div class="book-panel">
                <h3>Book Appointment</h3>
                <form action="" method="post">

                    <div class="input-groups">
                        <div class="input-group">
                            <label for="doctor_name">Doctor</label>
                            <input id="doctor_name" name="doctor_name" value="<?php echo h($selected_doctor_name); ?>" readonly type="text">
                            <input type="hidden" value="<?php echo $selected_doctor_id ?: ''; ?>" name="doctor_id" id="">
                        </div>
                        <div class="input-group">
                            <label  for="date">Date</label>
                            <input name="date" id="date" type="date">
                        </div>
                        <div class="input-group">
                            <label for="time">Time</label>
                            <input name="time" id="time" type="time">
                        </div>
                        <div class="input-group">
                            <label for="duration">duration (min)</label>
                            <select name="duration" id="">
                                <option value="15">15min</option>
                                <option value="30">30min</option>
                                <option value="45">45min</option>
                                <option value="60">60min</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="reason">Reason(optional)</label>
                            <input name="reason" id="reason" type="text">
                        </div>
                        <button type="submit">book</button>
                </form>
            </div>
        </div>


        </div>
    </main>

</body>

</html>