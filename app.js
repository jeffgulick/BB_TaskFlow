const API_URL = 'api.php';

// Saving all the DOM elements we will work with.
const todoList = document.getElementById('todo-list');
const inProgressList = document.getElementById('in_progress-list');
const doneList = document.getElementById('done-list');
const taskForm = document.getElementById('taskForm');
const taskTitleInput = document.getElementById('taskTitleInput');
const taskDescInput = document.getElementById('taskDescInput');

// Fetch and render existing tasks on page.
const fetchTasks = async () => {
    try {
        const response = await fetch(API_URL);
        if (!response.ok) throw new Error('Failed to fetch tasks');
        
        // Parse the JSON response and render the tasks
        const data = await response.json();
        renderBoard(data.tasks);
    } catch (error) {
        console.error('Error loading tasks:', error);
    }
}

// Render tasks into the UI
function renderBoard(tasks) {
    todoList.innerHTML = '';
    inProgressList.innerHTML = '';
    doneList.innerHTML = '';

    tasks.forEach(task => {
        const card = document.createElement('div');
        card.className = 'task-card'; // Assign a class for styling
        card.id = `task-${task.id}`; // Unique ID for each task card
        
        // --- DRAG AND DROP: Make the card draggable ---
        card.setAttribute('draggable', 'true');
        
        // When the user starts dragging the card
        card.addEventListener('dragstart', (e) => {
            // Store the task ID in the drag event data
            e.dataTransfer.setData('text/plain', task.id);
            // Use setTimeout to ensure the browser captures the drag image before making the original transparent
            setTimeout(() => card.classList.add('dragging'), 0);
        });

        // When the user lets go of the card
        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
        });
        // ----------------------------------------------

        // Escape data to prevent XSS issues
        const safeTitle = escapeHtml(task.title);
        const safeDesc = escapeHtml(task.description || '');
        
        // Ensure quotes don't break the HTML structure in inputs
        const inputTitle = safeTitle.replace(/"/g, '&quot;');
        const inputDesc = safeDesc.replace(/"/g, '&quot;');

        card.innerHTML = `
            <!-- VIEW MODE (Visible by default) -->
            <div id="view-${task.id}">
                <h3>${safeTitle}</h3>
                ${safeDesc ? `<p>${safeDesc}</p>` : ''}
                <small>Created: ${new Date(task.created_at).toLocaleString()}</small>

                <!--Checks for list location and renders buttons according to it-->
                <div class="task-actions" style="margin-top: 10px;">
                    ${task.status !== 'todo' ? `<button onclick="updateStatus('${task.id}', 'todo')">To Do</button>` : ''}
                    ${task.status !== 'in_progress' ? `<button onclick="updateStatus('${task.id}', 'in_progress')">Start Task</button>` : ''}
                    ${task.status !== 'done' ? `<button onclick="updateStatus('${task.id}', 'done')">Done</button>` : ''}
                    
                    <button onclick="toggleEditMode('${task.id}', true)">Edit</button>
                    <button onclick="deleteTask('${task.id}')" class="delete-btn">Delete</button>
                </div>
            </div>

            <!-- EDIT MODE (Hidden by default) -->
            <div id="edit-${task.id}" style="display: none;">
                <input type="text" id="edit-title-${task.id}" value="${inputTitle}" style="width: 100%; margin-bottom: 8px;" required>
                <input type="text" id="edit-desc-${task.id}" value="${inputDesc}" placeholder="Description (Optional)" style="width: 100%; margin-bottom: 12px;">
                
                <div class="task-actions">
                    <button onclick="saveInlineEdit('${task.id}')" style="background-color: #28a745; color: white;">Save</button>
                    <button onclick="toggleEditMode('${task.id}', false)" style="background-color: #6c757d; color: white;">Cancel</button>
                </div>
            </div>
        `;

        if (task.status === 'todo') {
            todoList.appendChild(card);
        } else if (task.status === 'in_progress') {
            inProgressList.appendChild(card);
        } else if (task.status === 'done') {
            doneList.appendChild(card);
        }
    });
}

// Submit new tasks via AJAX
taskForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const title = taskTitleInput.value.trim();
    const description = taskDescInput.value.trim();

    if (!title) return;

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            // Removed the status variable entirely, letting the PHP backend enforce 'todo'
            body: JSON.stringify({ title, description }) 
        });

        if (!response.ok) throw new Error('Failed to create task');

        // Clear the form and immediately update the UI
        taskTitleInput.value = '';
        taskDescInput.value = '';
        fetchTasks();
    } catch (error) {
        console.error('Error creating task:', error);
    }
});

// Update a task's status and sync changes via AJAX
async function updateStatus(id, newStatus) {
    try {
        const response = await fetch(`${API_URL}?id=${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: newStatus })
        });

        if (!response.ok) throw new Error('Failed to update status');
        fetchTasks();
    } catch (error) {
        console.error('Error updating status:', error);
    }
}

// Toggles the visibility of the View and Edit sections within a specific card
function toggleEditMode(id, isEditing) {
    const viewDiv = document.getElementById(`view-${id}`);
    const editDiv = document.getElementById(`edit-${id}`);
    
    if (isEditing) {
        viewDiv.style.display = 'none';
        editDiv.style.display = 'block';
    } else {
        viewDiv.style.display = 'block';
        editDiv.style.display = 'none';
    }
}

// Gathers the updated inputs and sends the PUT request to the backend
async function saveInlineEdit(id) {
    // Get the updated values from the input fields
    const newTitle = document.getElementById(`edit-title-${id}`).value.trim();
    const newDesc = document.getElementById(`edit-desc-${id}`).value.trim();

    if (!newTitle) {
        alert('Task title cannot be empty.');
        return;
    }

    try {
        const response = await fetch(`${API_URL}?id=${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            // Sending both title and description in the payload
            body: JSON.stringify({ title: newTitle, description: newDesc }) 
        });

        if (!response.ok) throw new Error('Failed to update task');
        
        // Refresh the board to pull the saved data from the server
        fetchTasks();
    } catch (error) {
        console.error('Error updating task:', error);
    }
}

// Delete tasks dynamically with immediate UI updates
async function deleteTask(id) {
    if (!confirm('Are you sure you want to delete this task?')) return;

    try {
        const response = await fetch(`${API_URL}?id=${id}`, {
            method: 'DELETE'
        });

        if (!response.ok) throw new Error('Failed to delete task');
        fetchTasks();
    } catch (error) {
        console.error('Error deleting task:', error);
    }
}

// Utility to sanitize HTML output
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>'"]/g, 
        tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
    );
}

// --- DRAG AND DROP: Set up Column Drop Zones ---
function setupDropZone(listElement, targetStatus) {
    // We attach events to the parent column so the user can drop anywhere inside the gray area
    const column = listElement.parentElement; 

    // Required to allow an element to be dropped
    column.addEventListener('dragover', (e) => {
        e.preventDefault(); 
    });

    // Add highlight when dragging into the column
    column.addEventListener('dragenter', (e) => {
        e.preventDefault();
        column.classList.add('drag-over');
    });

    // Remove highlight when leaving the column
    column.addEventListener('dragleave', () => {
        column.classList.remove('drag-over');
    });

    // Handle the actual drop
    column.addEventListener('drop', (e) => {
        e.preventDefault();
        column.classList.remove('drag-over'); // Clean up highlight
        
        // Retrieve the task ID we set during dragstart
        const taskId = e.dataTransfer.getData('text/plain');
        if (!taskId) return;

        // Call your existing function to move the card!
        updateStatus(taskId, targetStatus);
    });
}

// Initialize the three columns as drop zones
setupDropZone(todoList, 'todo');
setupDropZone(inProgressList, 'in_progress');
setupDropZone(doneList, 'done');

// Initialize the board
fetchTasks();