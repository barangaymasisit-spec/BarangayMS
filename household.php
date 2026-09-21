<?php
require_once __DIR__ . '/layout.php';

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = '(household_number LIKE ? OR household_head LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}
if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Total rows for the current filter (used by the pagination)
$countSql = 'SELECT COUNT(*) FROM households' . $whereSql;
if ($params) {
    $stmt = $conn->prepare($countSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $filteredCount = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
} else {
    $filteredCount = (int)($conn->query($countSql)->fetch_row()[0] ?? 0);
}

$totalPages = max(1, (int)ceil($filteredCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listSql = 'SELECT id, household_number, household_head, members_count, zone, status FROM households' . $whereSql . ' ORDER BY id DESC LIMIT ? OFFSET ?';
$stmt = $conn->prepare($listSql);
$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;
$stmt->bind_param($types . 'ii', ...$listParams);
$stmt->execute();
$households = $stmt->get_result();

$totalHouseholds = (int)($conn->query('SELECT COUNT(*) FROM households')->fetch_row()[0] ?? 0);
$activeHouseholds = (int)($conn->query("SELECT COUNT(*) FROM households WHERE status = 'Active'")->fetch_row()[0] ?? 0);
$archivedHouseholds = (int)($conn->query("SELECT COUNT(*) FROM households WHERE status = 'Archived'")->fetch_row()[0] ?? 0);
$totalMembers = (int)($conn->query('SELECT COALESCE(SUM(members_count), 0) FROM households')->fetch_row()[0] ?? 0);

function householdPageUrl($page, $search, $status) {
    $query = ['page' => $page];
    if ($search !== '') {
        $query['search'] = $search;
    }
    if ($status !== '') {
        $query['status'] = $status;
    }
    return 'household.php?' . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <?php pageTitle('Household'); ?>

    <link rel="stylesheet" href="<?php echo asset('household.css'); ?>">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">

</head>

<body>

<div class="wrapper">

    <?php renderSidebar('household', 'panel'); ?>

    <main class="main-content">

        <?php renderTopbar('Household Management', 'Manage household records and family information.', 'panel', ['breadcrumb' => 'Household', 'clock' => true]); ?>

        <?php renderNotice([
            'created' => 'Household record created.',
            'updated' => 'Household record updated.',
            'deleted' => 'Household record deleted.',
        ]); ?>

        <form
            class="page-actions"
            method="get"
            action="household.php"
            aria-label="Household Actions">

            <div class="search-box">

                <label
                    class="visually-hidden"
                    for="searchHousehold">

                    Search Household

                </label>

                <input
                    id="searchHousehold"
                    name="search"
                    type="search"
                    value="<?php echo h($search); ?>"
                    placeholder="Search household by house number or household head">

            </div>

            <div class="action-buttons">

                <label class="visually-hidden" for="statusFilter">Filter by Status</label>

                <select id="statusFilter" name="status" class="filter-select">
                    <option value="">All Status</option>
                    <option value="Active"<?php echo $statusFilter === 'Active' ? ' selected' : ''; ?>>Active</option>
                    <option value="Archived"<?php echo $statusFilter === 'Archived' ? ' selected' : ''; ?>>Archived</option>
                </select>

                <button
                    type="submit"
                    class="btn-filter"
                    aria-label="Filter Household Records">

                    <i
                        class="fa-solid fa-filter"
                        aria-hidden="true"></i>

                    <span>Filter</span>

                </button>

                <a
                    href="household_form.php"
                    class="btn-primary"
                    aria-label="Add New Household">

                    <i
                        class="fa-solid fa-plus"
                        aria-hidden="true"></i>

                    <span>Add Household</span>

                </a>

            </div>

        </form>

        <section class="cards">

            <article class="card">

                <i class="fa-solid fa-house" aria-hidden="true"></i>

                <h3>Total Households</h3>

                <h2 id="totalHouseholds"><?php echo $totalHouseholds; ?></h2>

            </article>

            <article class="card">

                <i class="fa-solid fa-house-circle-check" aria-hidden="true"></i>

                <h3>Active Households</h3>

                <h2 id="activeHouseholds"><?php echo $activeHouseholds; ?></h2>

            </article>

            <article class="card">

                <i class="fa-solid fa-box-archive" aria-hidden="true"></i>

                <h3>Archived Households</h3>

                <h2 id="archivedHouseholds"><?php echo $archivedHouseholds; ?></h2>

            </article>

            <article class="card">

                <i class="fa-solid fa-people-roof" aria-hidden="true"></i>

                <h3>Total Members</h3>

                <h2 id="totalMembers"><?php echo $totalMembers; ?></h2>

            </article>

        </section>

        <section
            class="table-card"
            aria-labelledby="householdRecordsHeading">

            <div class="table-header">

                <div>

                    <h2 id="householdRecordsHeading">
                        Household Records
                    </h2>

                    <p>
                        View and manage all registered households.
                    </p>

                </div>

            </div>

            <div class="table-responsive">

                <table>

                    <caption>

                        Household records maintained by the Barangay Management System.

                    </caption>

                    <thead>

                        <tr>

                            <th scope="col">House No.</th>
                            <th scope="col">Household Head</th>
                            <th scope="col">Members</th>
                            <th scope="col">Zone</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if ($households->num_rows === 0): ?>

                            <tr>
                                <td colspan="6">No household records found.</td>
                            </tr>

                        <?php else: ?>

                            <?php while ($household = $households->fetch_assoc()): ?>

                                <?php $householdName = trim((string)$household['household_head']); ?>

                                <tr>

                                    <td><?php echo h($household['household_number']); ?></td>

                                    <td><?php echo h($householdName); ?></td>

                                    <td><?php echo h($household['members_count']); ?></td>

                                    <td><?php echo h($household['zone']); ?></td>

                                    <td>

                                        <span class="status <?php echo strtolower((string)$household['status']); ?>">

                                            <?php echo h($household['status']); ?>

                                        </span>

                                    </td>

                                    <td>

                                        <div class="action-group">

                                            <a
                                                href="household_form.php?id=<?php echo (int)$household['id']; ?>"
                                                class="btn-view"
                                                aria-label="View household information for <?php echo h($householdName); ?>"
                                                title="View Household">

                                                <i
                                                    class="fa-solid fa-eye"
                                                    aria-hidden="true"></i>

                                            </a>

                                            <a
                                                href="household_form.php?id=<?php echo (int)$household['id']; ?>&amp;edit=1"
                                                class="btn-edit"
                                                aria-label="Edit household information for <?php echo h($householdName); ?>"
                                                title="Edit Household">

                                                <i
                                                    class="fa-solid fa-pen"
                                                    aria-hidden="true"></i>

                                            </a>

                                            <a
                                                href="household_form.php?id=<?php echo (int)$household['id']; ?>&amp;action=delete"
                                                class="btn-delete"
                                                onclick="return confirm('Delete household <?php echo h($household['household_number']); ?>?');"
                                                aria-label="Delete household information for <?php echo h($householdName); ?>"
                                                title="Delete Household">

                                                <i
                                                    class="fa-solid fa-trash"
                                                    aria-hidden="true"></i>

                                            </a>

                                        </div>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <nav
                class="pagination"
                aria-label="Household Table Pagination">

                <?php if ($page > 1): ?>
                    <a
                        href="<?php echo h(householdPageUrl($page - 1, $search, $statusFilter)); ?>"
                        class="page-btn"
                        aria-label="Previous Page">

                        Previous

                    </a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a
                        href="<?php echo h(householdPageUrl($i, $search, $statusFilter)); ?>"
                        class="page-number<?php echo $i === $page ? ' active' : ''; ?>"
                        aria-label="Page <?php echo $i; ?>"
                        <?php echo $i === $page ? 'aria-current="page"' : ''; ?>>

                        <?php echo $i; ?>

                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a
                        href="<?php echo h(householdPageUrl($page + 1, $search, $statusFilter)); ?>"
                        class="page-btn"
                        aria-label="Next Page">

                        Next

                    </a>
                <?php endif; ?>

            </nav>

        </section>

    </main>

</div>

<?php renderFooterScripts(false); ?>

</body>

</html>
