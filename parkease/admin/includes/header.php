<?php
/**
 * Admin Header Template - Glassmorphism Enhanced
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>SpaceNode Admin - <?php echo $page_title ?? 'Dashboard'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'DM Sans', sans-serif;
            background: radial-gradient(ellipse at 0% 0%, #1a1a2e 0%, #16213e 40%, #0f0f23 100%);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Animated mesh gradient overlay */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 50%, rgba(79,110,247,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(124,58,237,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 40% 20%, rgba(236,72,153,0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
            animation: meshPulse 12s ease-in-out infinite alternate;
        }
        
        @keyframes meshPulse {
            0% { opacity: 0.7; transform: scale(1); }
            100% { opacity: 1; transform: scale(1.02); }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: rgba(165,180,252,0.4); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(165,180,252,0.6); }
        
        .admin-container {
            display: flex;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }
        
        /* Glassmorphism Sidebar */
        .sidebar {
            width: 280px;
            background: rgba(15, 25, 45, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255,255,255,0.1);
            box-shadow: 5px 0 30px rgba(0,0,0,0.2);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 100;
        }
        
        .sidebar-header {
            padding: 30px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        
        .sidebar-header h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 5px;
        }
        
        .sidebar-header p {
            font-size: 11px;
            color: rgba(255,255,255,0.5);
        }
        
        .sidebar-menu {
            padding: 20px 0;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
            border-radius: 12px;
            margin: 0 10px;
        }
        
        .menu-item svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
        }
        
        .menu-item i {
            width: 20px;
            font-size: 16px;
        }
        
        .menu-item:hover, .menu-item.active {
            background: linear-gradient(135deg, rgba(79,110,247,0.2), rgba(124,58,237,0.2));
            color: #a5b4fc;
            border-left: 3px solid #4F6EF7;
            transform: translateX(5px);
        }
        
        /* Mobile menu button */
        .mobile-menu-btn {
            display: none;
            flex-direction: column;
            gap: 5px;
            cursor: pointer;
            padding: 8px;
            border-radius: 10px;
            transition: background 0.3s;
            width: 36px;
            height: 36px;
            align-items: center;
            justify-content: center;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 101;
        }
        
        .mobile-menu-btn:hover {
            background: rgba(255,255,255,0.12);
        }
        
        .mobile-menu-btn span {
            width: 20px;
            height: 2px;
            background: rgba(255,255,255,0.9);
            border-radius: 2px;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            display: block;
            transform-origin: center;
        }
        
        .mobile-menu-btn.open span:nth-child(1) {
            transform: rotate(45deg) translateY(9px);
        }
        
        .mobile-menu-btn.open span:nth-child(2) {
            opacity: 0;
            transform: scaleX(0);
        }
        
        .mobile-menu-btn.open span:nth-child(3) {
            transform: rotate(-45deg) translateY(-9px);
        }
        
        /* Mobile overlay */
        .mobile-overlay {
            display: none;
            position: fixed;
            top: 75px;
            left: 16px;
            right: 16px;
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(32px) saturate(180%);
            -webkit-backdrop-filter: blur(32px) saturate(180%);
            border: 1px solid rgba(255,255,255,0.28);
            border-radius: 28px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.18), inset 0 1px 0 rgba(255,255,255,0.4);
            padding: 20px;
            z-index: 99;
            opacity: 0;
            transform: translateY(-10px);
            pointer-events: none;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            max-height: calc(100vh - 100px);
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
        }
        
        .mobile-overlay::-webkit-scrollbar {
            width: 4px;
        }
        
        .mobile-overlay::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }
        
        .mobile-overlay::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 10px;
        }
        
        .mobile-overlay::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.5);
        }
        
        .mobile-overlay.active {
            display: block;
            opacity: 1;
            transform: translateY(0);
            pointer-events: all;
        }
        
        .mobile-menu-section {
            margin-bottom: 16px;
        }
        
        .mobile-menu-section:last-child {
            margin-bottom: 0;
        }
        
        .mobile-menu-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.5);
            padding: 0 16px;
            margin-bottom: 8px;
        }
        
        .mobile-overlay a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            font-size: 15px;
            font-weight: 500;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            border-radius: 16px;
            transition: all 0.25s cubic-bezier(0.4,0,0.2,1);
            margin-bottom: 6px;
            background: transparent;
            position: relative;
        }
        
        .mobile-overlay a::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(79,110,247,0.08);
            border-radius: 16px;
            opacity: 0;
            transition: opacity 0.25s;
        }
        
        .mobile-overlay a:hover::before {
            opacity: 1;
        }
        
        .mobile-overlay a:hover {
            color: #a5b4fc;
            transform: translateX(4px);
        }
        
        .mobile-overlay a i {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .mobile-menu-divider {
            height: 1px;
            background: rgba(255,255,255,0.2);
            margin: 12px 0;
            border-radius: 1px;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            min-width: 0;
            margin-left: 280px;
            padding: 30px;
            position: relative;
            z-index: 1;
        }
        
        /* Glassmorphism Top Bar */
        .top-bar {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            transition: all 0.3s ease;
        }
        
        .top-bar:hover {
            background: rgba(255,255,255,0.12);
            border-color: rgba(255,255,255,0.25);
        }
        
        .page-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .admin-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .admin-badge {
            background: linear-gradient(135deg, rgba(79,110,247,0.2), rgba(124,58,237,0.2));
            border: 1px solid rgba(165,180,252,0.3);
            color: #a5b4fc;
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .admin-name {
            color: rgba(255,255,255,0.8);
            font-weight: 500;
            font-size: 14px;
        }
        
        .logout-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(239,68,68,0.15);
            border: 1px solid rgba(239,68,68,0.3);
            color: #f87171;
            padding: 8px 18px;
            border-radius: 50px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background: rgba(239,68,68,0.25);
            transform: translateY(-2px);
            color: #fca5a5;
        }
        
        /* Glassmorphism Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.12);
            border-color: rgba(255,255,255,0.3);
            box-shadow: 0 20px 48px rgba(0,0,0,0.3);
        }
        
        .stat-card h3 {
            font-size: 14px;
            font-weight: 500;
            color: rgba(255,255,255,0.7);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stat-card h3 i {
            color: #a5b4fc;
        }
        
        .stat-number {
            font-family: 'Outfit', sans-serif;
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Glassmorphism Table Container */
        .table-container {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            overflow-x: auto;
        }
        
        .table-container:hover {
            background: rgba(255,255,255,0.12);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: transparent !important;
            color: rgba(255,255,255,0.95) !important;
        }
        
        th {
            text-align: left;
            padding: 14px;
            background: rgba(255,255,255,0.07) !important;
            color: rgba(255,255,255,0.95) !important;
            font-weight: 600;
            font-size: 13px;
            border-bottom: 1px solid rgba(255,255,255,0.12);
        }
        
        td {
            padding: 14px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            font-size: 14px;
            color: rgba(255,255,255,0.92) !important;
            background: transparent !important;
        }
        
        tr:hover td {
            background: rgba(255,255,255,0.06) !important;
        }

        /* DataTables glass override */
        table.dataTable {
            background: transparent !important;
            border: none !important;
        }

        table.dataTable thead th, table.dataTable thead td {
            background: rgba(255,255,255,0.08) !important;
            color: rgba(255,255,255,0.95) !important;
            border-bottom: 1px solid rgba(255,255,255,0.16) !important;
        }

        table.dataTable tbody tr {
            background: transparent !important;
        }

        table.dataTable tbody tr:hover {
            background: rgba(255,255,255,0.08) !important;
        }

        table.dataTable tbody td {
            border-bottom: 1px solid rgba(255,255,255,0.10) !important;
            color: rgba(255,255,255,0.92) !important;
        }

        .dataTables_wrapper .dataTables_scrollBody table {
            background: transparent !important;
        }

        .dataTables_wrapper .dataTables_scrollBody {
            background: transparent !important;
        }

        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            color: white !important;
        }
        
        /* Glassmorphism Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            backdrop-filter: blur(8px);
        }
        
        .badge-success { background: rgba(34,197,94,0.15); color: #4ade80; border: 1px solid rgba(34,197,94,0.2); }
        .badge-warning { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.2); }
        .badge-danger { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
        .badge-info { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.2); }
        
        /* Glassmorphism Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border: none;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            font-family: 'Outfit', sans-serif;
            position: relative;
            overflow: hidden;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 11px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -75%;
            width: 50%;
            height: 200%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transform: skewX(-20deg);
            transition: left 0.5s ease;
        }
        
        .btn-primary:hover::before {
            left: 130%;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
        }
        
        .btn-danger {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
        }
        
        .btn-danger:hover {
            background: rgba(239,68,68,0.25);
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
            box-shadow: 0 4px 15px rgba(16,185,129,0.3);
        }
        
        .btn-success::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -75%;
            width: 50%;
            height: 200%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transform: skewX(-20deg);
            transition: left 0.5s ease;
        }
        
        .btn-success:hover::before {
            left: 130%;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16,185,129,0.4);
        }
        
        .btn-info {
            background: rgba(59,130,246,0.15);
            color: #60a5fa;
            border: 1px solid rgba(59,130,246,0.3);
        }
        
        .btn-info:hover {
            background: rgba(59,130,246,0.25);
            transform: translateY(-2px);
        }
        
        /* Glassmorphism Form Controls */
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 500;
            color: rgba(255,255,255,0.7);
        }
        
        .form-control {
            width: 100%;
            padding: 10px 16px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 50px;
            font-size: 13px;
            font-family: 'DM Sans', sans-serif;
            color: white;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.15);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
        }
        
        .form-control::placeholder {
            color: rgba(255,255,255,0.4);
        }
        
        select.form-control option {
            background: #1a1a2e;
            color: white;
        }
        
        textarea.form-control {
            border-radius: 16px;
            resize: vertical;
        }
        
        /* Glassmorphism Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 20px;
            margin-bottom: 20px;
            backdrop-filter: blur(20px);
            animation: slideDown 0.4s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            border: 1px solid rgba(34,197,94,0.3);
        }
        
        .alert-error {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
        }
        
        /* Glassmorphism Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-content {
            background: rgba(26,26,46,0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 28px;
            padding: 32px;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            animation: modalIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.9) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        
        .modal-content h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        
        .modal-actions .btn {
            flex: 1;
            justify-content: center;
        }
        
        /* DataTables Custom Styling */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 15px;
            color: rgba(255,255,255,0.9) !important;
        }
        
        .dataTables_wrapper .dataTables_length label,
        .dataTables_wrapper .dataTables_filter label {
            color: rgba(255,255,255,0.9) !important;
        }
        
        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 50px;
            color: white;
            padding: 6px 12px;
        }
        
        .dataTables_wrapper .dataTables_filter input {
            padding: 6px 16px;
        }
        
        .dataTables_wrapper .dataTables_filter input::placeholder {
            color: rgba(255,255,255,0.4);
        }
        
        .dataTables_wrapper .dataTables_length select option {
            background: #1a1a2e;
            color: white;
        }
        
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            color: rgba(255,255,255,0.9) !important;
            margin-top: 15px;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 50px;
            color: rgba(255,255,255,0.8) !important;
            padding: 5px 12px;
            margin: 0 3px;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            border-color: transparent;
            color: white !important;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: rgba(79,110,247,0.3);
            border-color: rgba(79,110,247,0.5);
            color: white !important;
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                transform: translateX(-100%);
                z-index: 1000;
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-menu-btn {
                display: flex;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                text-align: center;
                padding: 16px 20px;
            }
            
            .admin-info {
                justify-content: center;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            th, td {
                white-space: nowrap;
            }
            
            .modal-content {
                padding: 24px;
                width: 95%;
            }
        }
        
        @media (max-width: 480px) {
            .main-content {
                padding: 15px;
            }
            
            .stat-number {
                font-size: 24px;
            }
            
            .page-title h1 {
                font-size: 20px;
            }
            
            .btn {
                padding: 6px 12px;
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Button -->
    <div class="mobile-menu-btn" onclick="toggleMobileMenu()" aria-label="Toggle menu">
        <span></span><span></span><span></span>
    </div>
    
    <!-- Mobile Overlay -->
    <div class="mobile-overlay" id="mobileOverlay">
        <!-- Admin Menu Section -->
        <div class="mobile-menu-section">
            <div class="mobile-menu-label">Admin Panel</div>
            <a href="index.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="users.php">
                <i class="fas fa-users"></i>
                <span>Users</span>
            </a>
            <a href="bookings.php">
                <i class="fas fa-calendar-check"></i>
                <span>Bookings</span>
            </a>
        </div>

        <div class="mobile-menu-divider"></div>

        <!-- Analytics Section -->
        <div class="mobile-menu-section">
            <div class="mobile-menu-label">Analytics & Finance</div>
            <a href="commission.php">
                <i class="fas fa-percent"></i>
                <span>Commission</span>
            </a>
            <a href="payouts.php">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payouts</span>
            </a>
            <a href="reports.php">
                <i class="fas fa-chart-line"></i>
                <span>Reports</span>
            </a>
        </div>

        <div class="mobile-menu-divider"></div>

        <!-- Settings Section -->
        <div class="mobile-menu-section">
            <div class="mobile-menu-label">Settings</div>
            <a href="newsletter.php">
                <i class="fas fa-envelope"></i>
                <span>Newsletter</span>
            </a>
            <a href="settings.php">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
            <a href="logout.php" style="color: #f87171;">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
    
    <div class="admin-container">
        <!-- Glassmorphism Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-parking"></i> SpaceNode Admin</h2>
                <p>Version 2.0</p>
            </div>
            <div class="sidebar-menu">
                <a href="index.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="users.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
                <a href="bookings.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'bookings.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i>
                    <span>Bookings</span>
                </a>
                <a href="commission.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'commission.php' ? 'active' : ''; ?>">
                    <i class="fas fa-percent"></i>
                    <span>Commission</span>
                </a>
                <a href="payouts.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'payouts.php' ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Payouts</span>
                </a>
                <a href="reports.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    <span>Reports</span>
                </a>
                <a href="newsletter.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'newsletter.php' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i>
                    <span>Newsletter</span>
                </a>
                <a href="settings.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">

<script>
    // Mobile menu functions
    function toggleMobileMenu() {
        const overlay = document.getElementById('mobileOverlay');
        const btn = document.querySelector('.mobile-menu-btn');
        overlay.classList.toggle('active');
        if (btn) btn.classList.toggle('open');
        document.body.style.overflow = overlay.classList.contains('active') ? 'hidden' : '';
    }
    
    function closeMobileMenu() {
        const overlay = document.getElementById('mobileOverlay');
        const btn = document.querySelector('.mobile-menu-btn');
        overlay.classList.remove('active');
        if (btn) btn.classList.remove('open');
        document.body.style.overflow = '';
    }
    
    // Close mobile menu when clicking a link
    const mobileLinks = document.querySelectorAll('.mobile-overlay a');
    mobileLinks.forEach(link => {
        link.addEventListener('click', closeMobileMenu);
    });
    
    // Close mobile menu when clicking outside
    document.addEventListener('click', function(e) {
        const overlay = document.getElementById('mobileOverlay');
        const btn = document.querySelector('.mobile-menu-btn');
        if (overlay && overlay.classList.contains('active') && !overlay.contains(e.target) && !btn?.contains(e.target)) {
            closeMobileMenu();
        }
    });
    
    // Close menu when window resizes above mobile
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            closeMobileMenu();
        }
    });
</script>