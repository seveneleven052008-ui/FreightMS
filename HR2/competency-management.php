<?php
require_once 'config/config.php';
requireLogin();

$pageTitle = 'Competency Management';
$pdo = getDBConnection();

$activeTab = $_GET['tab'] ?? 'gap-analysis';
$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role, username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();
$roleValue = strtolower($currentUser['role'] ?? '');
$isAdmin = ($roleValue === 'admin') || (($currentUser['username'] ?? '') === 'admin');

// Get competency gaps (including HR4 Talent)
$gaps = $pdo->query("
    SELECT 
        cg.id, cg.employee_id, cg.role, cg.department, 
        cg.required_competencies, cg.current_competencies, cg.gap_percentage,
        CASE 
            WHEN ti.talent_type = 'Key Role Talent' THEN 'Key Role (HR4)'
            WHEN ti.talent_type = 'Core Human Capital' THEN 'Core Human Capital (HR4)'
            ELSE cg.critical_gaps 
        END AS display_gaps,
        cg.created_at, cg.updated_at,
        u.full_name, u.department as user_dept,
        CASE WHEN ti.talent_type IN ('Key Role Talent', 'Core Human Capital') THEN 1 ELSE 0 END as is_hr4,
        ti.talent_type
    FROM competency_gaps cg
    JOIN users u ON cg.employee_id = u.id
    LEFT JOIN talent_identification ti ON u.id = ti.employee_id AND ti.talent_type IN ('Key Role Talent', 'Core Human Capital')
    UNION
    SELECT 
        NULL as id, 
        ti.employee_id, 
        u.position as role, 
        u.department, 
        0 as required_competencies, 
        0 as current_competencies, 
        100 as gap_percentage, 
        CASE 
            WHEN ti.talent_type = 'Key Role Talent' THEN 'Key Role (HR4)'
            WHEN ti.talent_type = 'Core Human Capital' THEN 'Core Human Capital (HR4)'
            ELSE 'Identified for Development (HR4)'
        END as display_gaps, 
        ti.created_at, 
        ti.created_at as updated_at, 
        u.full_name, 
        u.department as user_dept,
        1 as is_hr4,
        ti.talent_type
    FROM talent_identification ti
    JOIN users u ON ti.employee_id = u.id
    WHERE ti.talent_type IN ('Key Role Talent', 'Core Human Capital')
    AND ti.employee_id NOT IN (SELECT employee_id FROM competency_gaps)
    ORDER BY is_hr4 DESC, gap_percentage DESC
")->fetchAll();

// Get skill assessments
$assessments = $pdo->query("
    SELECT sa.*, u.full_name, u.department
    FROM skill_assessments sa
    JOIN users u ON sa.employee_id = u.id
    ORDER BY sa.assessment_date DESC
")->fetchAll();

// Get competency matrix
$matrix = $pdo->query("
    SELECT cm.*, 
           GROUP_CONCAT(CONCAT(ec.level, ':', u.full_name, ':', ec.has_gap) SEPARATOR '|') as employees
    FROM competency_matrix cm
    LEFT JOIN employee_competencies ec ON cm.id = ec.competency_id
    LEFT JOIN users u ON ec.employee_id = u.id
    GROUP BY cm.id
")->fetchAll();

ob_start();
?>

<div class="p-8">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Competency Management</h1>
        <p class="text-gray-600">
            Analyze skill gaps, assess employee competencies, and track developments
        </p>
    </div>

    <!-- Tabs -->
    <div class="mb-6 border-b border-gray-200">
        <div class="flex gap-4 overflow-x-auto">
            <a href="?tab=gap-analysis" class="pb-3 px-2 border-b-2 transition-colors whitespace-nowrap <?php echo $activeTab == 'gap-analysis' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-600 hover:text-gray-900'; ?>">
                <div class="flex items-center gap-2">
                    <i class="fas fa-bullseye"></i>
                    <span>Competency Gap Analysis</span>
                </div>
            </a>
            <a href="?tab=assessments" class="pb-3 px-2 border-b-2 transition-colors whitespace-nowrap <?php echo $activeTab == 'assessments' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-600 hover:text-gray-900'; ?>">
                <div class="flex items-center gap-2">
                    <i class="fas fa-chart-bar"></i>
                    <span>Skill Assessments</span>
                </div>
            </a>
            <a href="?tab=matrix" class="pb-3 px-2 border-b-2 transition-colors whitespace-nowrap <?php echo $activeTab == 'matrix' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-600 hover:text-gray-900'; ?>">
                <div class="flex items-center gap-2">
                    <i class="fas fa-award"></i>
                    <span>Competency Matrix</span>
                </div>
            </a>
        </div>
    </div>

    <!-- Gap Analysis Tab -->
    <?php if ($activeTab == 'gap-analysis'): ?>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 mb-2">Employee Competency Gaps</h2>
                    <p class="text-gray-600 text-sm">
                        <i class="fas fa-info-circle mr-2"></i>
                        Analyze the difference between required competencies for roles and employees' current skill levels. High gap percentages indicate areas where training and development are critical.
                    </p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200">
                            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Employee ID</th>
                            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Department</th>
                            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Position</th>
                            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-right">Gap %</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($gaps as $gap): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4">
                                    <span class="text-sm font-medium text-gray-500">#<?php echo str_pad($gap['employee_id'], 4, '0', STR_PAD_LEFT); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 text-xs font-bold">
                                            <?php echo substr($gap['full_name'], 0, 1); ?>
                                        </div>
                                        <p class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($gap['full_name']); ?></p>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-sm text-gray-700"><?php echo htmlspecialchars($gap['user_dept']); ?></span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="text-sm text-gray-700"><?php echo htmlspecialchars($gap['role']); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($gap['is_hr4']): ?>
                                        <?php 
                                            $badgeClass = $gap['talent_type'] === 'ACTIVE' ? 'bg-green-50 text-green-700 border-green-100' : 
                                                         ($gap['talent_type'] === 'INACTIVE' ? 'bg-red-50 text-red-700 border-red-100' : 'bg-blue-50 text-blue-700 border-blue-100');
                                            $icon = $gap['talent_type'] === 'ACTIVE' ? 'fa-check-circle' : ($gap['talent_type'] === 'INACTIVE' ? 'fa-times-circle' : 'fa-star');
                                        ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider <?php echo $badgeClass; ?> border">
                                            <i class="fas <?php echo $icon; ?> mr-1"></i> 
                                            <?php echo htmlspecialchars($gap['talent_type']); ?> (HR4)
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-gray-100 text-gray-600 border border-gray-200">
                                            Internal Gap
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <span class="px-3 py-1 rounded-full text-xs font-black <?php echo $gap['gap_percentage'] > 25 ? 'bg-red-50 text-red-700 border border-red-100' : ($gap['gap_percentage'] > 15 ? 'bg-yellow-50 text-yellow-700 border border-yellow-100' : 'bg-green-50 text-green-700 border border-green-100'); ?>">
                                        <?php echo $gap['gap_percentage']; ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($gaps)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                    <i class="fas fa-inbox text-4xl mb-4 block opacity-20"></i>
                                    <p class="text-lg">No competency gaps found</p>
                                    <p class="text-sm">All employees have met their competency requirements</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <script>
            function initiateAssessment(employeeId) {
                if(!confirm('Are you sure you want to initiate a skill assessment based on current competency gaps?')) return;
                
                const formData = new FormData();
                formData.append('action', 'initiate_assessment_from_gap');
                formData.append('employee_id', employeeId);

                fetch('competency-ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Success', 'Assessment initiated successfully!', 'success')
                            .then(() => window.location.href = '?tab=assessments');
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                });
            }

            function viewGapDetails(gapId) {
                Swal.fire('Notice', 'Detailed breakdown of skills is coming soon.', 'info');
            }
            </script>

            <!-- Development Plan Modal -->
            <div id="devPlanModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
                <div class="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
                    <div class="p-6 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-xl font-bold text-gray-900">Training Recommendations</h3>
                        <button onclick="closeDevPlanModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    <div class="p-6">
                        <p class="font-semibold text-gray-900 mb-2">Employee: <span id="devPlanEmployeeName" class="text-indigo-600"></span></p>
                        <hr class="my-4">
                        <div id="devPlanContent"></div>
                    </div>
                    <div class="p-4 border-t border-gray-200 flex justify-end">
                        <button onclick="closeDevPlanModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">Close</button>
                    </div>
                </div>
            </div>


        </div>
    <?php endif; ?>

    <!-- Skill Assessments Tab -->
    <?php if ($activeTab == 'assessments'): ?>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 mb-2">Skill Assessment & Evaluation</h2>
                    <p class="text-gray-600 text-sm">
                        <i class="fas fa-info-circle mr-2"></i>
                        Conduct and track formal assessments of employee skills across different competency categories. Assessments help validate competency levels and guide development planning.
                    </p>
                </div>
                <?php if ($isAdmin): ?>
                    <button onclick="showScheduleAssessmentModal()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 whitespace-nowrap">
                        Schedule Assessment
                    </button>
                <?php endif; ?>
            </div>

            <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <p class="text-yellow-800 text-sm"><strong>Purpose:</strong> Schedule regular assessments to measure competency development. Track progress over time and identify areas where training has been effective or needs adjustment.</p>
            </div>

            <div class="space-y-6">
                <?php foreach ($assessments as $assessment): ?>
                    <div class="border border-gray-200 rounded-lg p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 mb-1"><?php echo htmlspecialchars($assessment['full_name']); ?></h3>
                                <p class="text-gray-600"><?php echo htmlspecialchars($assessment['role']); ?></p>
                                <p class="text-gray-500 text-sm">
                                    Assessed on: <?php echo formatDate($assessment['assessment_date']); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <span class="px-3 py-1 rounded-full text-sm <?php echo getStatusBadge($assessment['status']); ?>">
                                    <?php echo htmlspecialchars($assessment['status']); ?>
                                </span>
                                <?php if ($assessment['status'] == 'In Progress' && $isAdmin): ?>
                                    <button onclick="evaluateAssessment(<?php echo $assessment['id']; ?>, '<?php echo htmlspecialchars(addslashes($assessment['full_name'])); ?>')" class="mt-2 block w-full px-3 py-1 bg-indigo-600 text-white rounded text-sm hover:bg-indigo-700">
                                        Evaluate
                                    </button>
                                <?php endif; ?>
                                <?php if ($assessment['status'] == 'Completed' && $assessment['overall_score']): ?>
                                    <p class="text-gray-900 mt-2 font-semibold">
                                        Overall Score: <?php echo $assessment['overall_score']; ?>%
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($assessment['status'] == 'Completed'): ?>
                            <?php
                            $categories = $pdo->prepare("SELECT * FROM assessment_categories WHERE assessment_id = ?");
                            $categories->execute([$assessment['id']]);
                            $cats = $categories->fetchAll();
                            ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($cats as $cat): ?>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-gray-700"><?php echo htmlspecialchars($cat['category_name']); ?></p>
                                            <span class="px-2 py-1 rounded-full text-sm <?php echo getLevelBadge($cat['level']); ?>">
                                                <?php echo htmlspecialchars($cat['level']); ?>
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <div class="flex-1 bg-gray-200 rounded-full h-2">
                                                <div
                                                    class="bg-indigo-500 h-2 rounded-full assessment-bar"
                                                    style="width: 0%"
                                                    data-target="<?php echo $cat['score']; ?>"
                                                ></div>
                                            </div>
                                            <span class="text-gray-900 text-sm assessment-score">0%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Competency Matrix Tab -->
    <?php if ($activeTab == 'matrix'): ?>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="mb-6">
                <h2 class="text-xl font-bold text-gray-900 mb-2">Competency Matrix</h2>
                <p class="text-gray-600 text-sm mb-4">
                    <i class="fas fa-info-circle mr-2"></i>
                    View and compare competency levels across all employees for each core competency. Identify which employees meet required levels and who needs development.
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <i class="fas fa-check-circle text-green-600"></i>
                            <span class="text-green-900 font-semibold">Competency Met</span>
                        </div>
                        <p class="text-green-800 text-sm">Employee has reached or exceeded the required competency level for this role.</p>
                    </div>
                    <div class="bg-orange-50 border border-orange-200 rounded-lg p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <i class="fas fa-arrow-down text-orange-600"></i>
                            <span class="text-orange-900 font-semibold">Gap Identified</span>
                        </div>
                        <p class="text-orange-800 text-sm">Employee has not yet reached the required competency level and needs targeted development.</p>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <?php foreach ($matrix as $comp): ?>
                    <div class="border border-gray-200 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 mb-1"><?php echo htmlspecialchars($comp['competency']); ?></h3>
                                <p class="text-gray-600 text-sm">
                                    Required Level: <span class="px-2 py-1 rounded <?php echo getLevelBadge($comp['required_level']); ?>">
                                        <?php echo htmlspecialchars($comp['required_level']); ?>
                                    </span>
                                </p>
                            </div>
                        </div>

                        <?php if (!empty($comp['employees'])): ?>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <?php
                                $employees = explode('|', $comp['employees']);
                                foreach ($employees as $empData):
                                    if (empty($empData)) continue;
                                    list($level, $name, $hasGap) = explode(':', $empData);
                                ?>
                                    <div class="p-4 rounded-lg border-2 <?php echo $hasGap == '1' ? 'border-orange-200 bg-orange-50' : 'border-green-200 bg-green-50'; ?>">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-gray-900 font-semibold"><?php echo htmlspecialchars($name); ?></p>
                                            <?php if ($hasGap == '1'): ?>
                                                <i class="fas fa-arrow-down text-orange-600"></i>
                                            <?php else: ?>
                                                <i class="fas fa-check-circle text-green-600"></i>
                                            <?php endif; ?>
                                        </div>
                                        <span class="px-2 py-1 rounded text-sm <?php echo getLevelBadge($level); ?>">
                                            <?php echo htmlspecialchars($level); ?>
                                        </span>
                                        <?php if ($hasGap == '1'): ?>
                                            <p class="text-orange-700 text-sm mt-2">Gap Identified</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Schedule Assessment Modal -->
<div id="scheduleAssessmentModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-900">Schedule Skill Assessment</h3>
            <button onclick="closeScheduleAssessmentModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form id="scheduleAssessmentForm" method="POST" action="" class="p-6">
            <div class="space-y-4">
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-2">Select Employee *</label>
                    <select name="employee_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="">Choose Employee</option>
                        <?php 
                        $allEmployees = $pdo->query("SELECT id, full_name, department FROM users WHERE role != 'admin' ORDER BY full_name")->fetchAll();
                        foreach ($allEmployees as $emp): 
                        ?>
                            <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['full_name'] . ' - ' . $emp['department']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-2">Assessment Date *</label>
                    <input type="date" name="assessment_date" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-2">Role</label>
                    <input type="text" name="role" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeScheduleAssessmentModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                    Schedule Assessment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Animate progress bars on page load
document.addEventListener('DOMContentLoaded', function() {
    // Animate competency bars
    const competencyBars = document.querySelectorAll('.competency-bar');
    competencyBars.forEach(bar => {
        const target = parseFloat(bar.getAttribute('data-target')) || 0;
        animateProgressBar(bar, target);
    });

    // Animate assessment bars
    const assessmentBars = document.querySelectorAll('.assessment-bar');
    assessmentBars.forEach(bar => {
        const target = parseInt(bar.getAttribute('data-target')) || 0;
        const scoreSpan = bar.closest('.flex').querySelector('.assessment-score');
        animateProgressBar(bar, target, scoreSpan);
    });

    // Add hover effects to cards
    const cards = document.querySelectorAll('.border.border-gray-200.rounded-lg');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.transition = 'transform 0.2s ease, box-shadow 0.2s ease';
            this.style.boxShadow = '0 4px 6px rgba(0, 0, 0, 0.1)';
        });
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '';
        });
    });
});

// Animate Progress Bar
function animateProgressBar(element, target, percentageSpan) {
    if (!element) return;
    let current = 0;
    const increment = target / 50;
    const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
            element.style.width = target + '%';
            if (percentageSpan) percentageSpan.textContent = target + '%';
            clearInterval(timer);
        } else {
            element.style.width = current + '%';
            if (percentageSpan) percentageSpan.textContent = Math.floor(current) + '%';
        }
    }, 30);
}
</script>

<!-- Evaluate Assessment Modal -->
<div id="evaluateModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-900">Evaluate Skill Assessment</h3>
            <button onclick="closeEvaluateModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form id="evaluateForm" class="p-6">
            <input type="hidden" name="action" value="submit_assessment">
            <input type="hidden" name="assessment_id" id="evalAssessmentId">
            <div class="mb-4">
                <p class="font-semibold text-gray-900">Employee: <span id="evalEmployeeName" class="text-indigo-600"></span></p>
            </div>
            <div id="evalCategoriesContainer" class="space-y-6">
                <!-- Categories will be loaded here -->
            </div>
            <div class="mt-8 flex justify-end gap-3">
                <button type="button" onclick="closeEvaluateModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-bold shadow-lg">Submit Evaluation</button>
            </div>
        </form>
    </div>
</div>

<script>
function evaluateAssessment(id, name) {
    document.getElementById('evalAssessmentId').value = id;
    document.getElementById('evalEmployeeName').textContent = name;
    document.getElementById('evalCategoriesContainer').innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin text-2xl text-indigo-600"></i></div>';
    document.getElementById('evaluateModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    fetch(`competency-ajax.php?action=get_assessment_details&id=${id}`)
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                let html = '';
                data.categories.forEach(cat => {
                    html += `
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <h4 class="font-bold text-gray-900 mb-4">${cat.category_name}</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-600 mb-1">Score (0-100)</label>
                                    <input type="number" name="score[${cat.id}]" min="0" max="100" value="${cat.score || 0}" required class="w-full px-3 py-2 border rounded">
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600 mb-1">Proficiency Level</label>
                                    <select name="level[${cat.id}]" required class="w-full px-3 py-2 border rounded">
                                        <option value="Beginner" ${cat.level == 'Beginner' ? 'selected' : ''}>Beginner</option>
                                        <option value="Intermediate" ${cat.level == 'Intermediate' ? 'selected' : ''}>Intermediate</option>
                                        <option value="Advanced" ${cat.level == 'Advanced' ? 'selected' : ''}>Advanced</option>
                                        <option value="Expert" ${cat.level == 'Expert' ? 'selected' : ''}>Expert</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    `;
                });
                document.getElementById('evalCategoriesContainer').innerHTML = html;
            }
        });
}

function closeEvaluateModal() {
    document.getElementById('evaluateModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

document.getElementById('evaluateForm').onsubmit = function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    Swal.fire({
        title: 'Submitting...',
        didOpen: () => Swal.showLoading()
    });

    fetch('competency-ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            Swal.fire('Success', 'Assessment completed and matrix updated!', 'success')
                .then(() => window.location.reload());
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    });
};

function showScheduleAssessmentModal() {
    const modal = document.getElementById('scheduleAssessmentModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeScheduleAssessmentModal() {
    const modal = document.getElementById('scheduleAssessmentModal');
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
    document.getElementById('scheduleAssessmentForm').reset();
}

document.getElementById('scheduleAssessmentForm').onsubmit = function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'schedule_assessment');

    fetch('competency-ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', 'Assessment scheduled successfully!', 'success')
                .then(() => window.location.reload());
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    });
};

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('scheduleAssessmentModal');
    if (event.target === modal) {
        closeScheduleAssessmentModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeScheduleAssessmentModal();
    }
});
</script>

<?php
$content = ob_get_clean();
require_once 'includes/layout.php';
require_once 'includes/footer.php';
?>
